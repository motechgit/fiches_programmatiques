<?php
/**
 * VacataireDossierRepository
 * Gestion des dossiers VACATAIRE : Fiche + Demande vacation + Acte nomination
 */

// Import du générateur d'acte officiel UJKZ
require_once __DIR__ . '/ActeNominationGenerator.php';

class VacataireDossierRepository
{
    private $pdo;
    
    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }
    
    /**
     * Créer un dossier VACATAIRE après validation fiche
     * 
     * WORKFLOW CORRECT : 
     * 1. VACATAIRE soumet fiche + upload Diplôme/nomination au formulaire
     * 2. Demande vacation générée automatiquement
     * 3. VALIDATION FICHE : Chef → Dir adj → Dir → DEI (4 niveaux)
     * 4. Une fois fiche VALIDÉE PAR DEI : DOSSIER VACATAIRE CRÉÉ
     * 5. DEI VALIDE DOSSIER → Acte de nomination généré
     * 6. VP EIP : accès lecture seule, peut télécharger documents
     */
    public function creerDossier(int $ficheId, int $enseignantId, ?int $userIdCreateur = null): int
    {
        // Récupérer données fiche
        $ficheData = $this->getFicheData($ficheId);
        if (!$ficheData) return 0;
        
        // Vérifier pas de dossier existant
        $existing = $this->getDossierByFicheId($ficheId);
        if ($existing) return (int)$existing['id'];
        
        // Créer dossier
        $stmt = $this->pdo->prepare(
            "INSERT INTO vacataire_dossier 
             (fiche_id, enseignant_id, annee_academique, statut_dossier, statut_dei, created_at, created_by)
             VALUES (?, ?, ?, 'complet', 'en_attente', NOW(), ?)"
        );
        $stmt->execute([$ficheId, $enseignantId, $ficheData['annee_academique'], $userIdCreateur]);
        
        return (int)$this->pdo->lastInsertId();
    }
    
    /**
     * Récupérer données fiche pour dossier
     */
    private function getFicheData(int $ficheId): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT f.*, e.nom, e.prenom, e.grade, e.diplome, e.specialite, e.telephone,
                    etab.nom as etab_nom
             FROM fiches f
             LEFT JOIN enseignants e ON f.enseignant_id = e.id
             LEFT JOIN etablissements etab ON f.etab_beneficiaire_fiche = etab.id
             WHERE f.id = ?"
        );
        $stmt->execute([$ficheId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    
    /**
     * Récupérer dossier par fiche_id
     */
    public function getDossierByFicheId(int $ficheId): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT vd.*, f.*, e.nom, e.prenom, e.grade, e.diplome, e.specialite,
                    etab.id as etab_id, etab.nom as etab_nom
             FROM vacataire_dossier vd
             JOIN fiches f ON vd.fiche_id = f.id
             JOIN enseignants e ON vd.enseignant_id = e.id
             LEFT JOIN etablissements etab ON f.etab_beneficiaire_fiche = etab.id
             WHERE vd.fiche_id = ?"
        );
        $stmt->execute([$ficheId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    
    /**
     * Récupérer dossier par ID
     */
    public function getDossier(int $dossierId): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT vd.*, f.*, e.nom, e.prenom, e.grade, e.diplome, e.specialite,
                    etab.id as etab_id, etab.nom as etab_nom
             FROM vacataire_dossier vd
             JOIN fiches f ON vd.fiche_id = f.id
             JOIN enseignants e ON vd.enseignant_id = e.id
             LEFT JOIN etablissements etab ON f.etab_beneficiaire_fiche = etab.id
             WHERE vd.id = ?"
        );
        $stmt->execute([$dossierId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    
    /**
     * Récupérer établissement
     */
    private function getEtablissement(int $etablissementId): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM etablissements WHERE id = ?");
        $stmt->execute([$etablissementId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    
    /**
     * Valider dossier VACATAIRE (DEI)
     * 
     * SÉCURITÉ : Vérifier que fiche est validée par DEI avant génération acte
     */
    public function validerDEI(int $dossierId, int $userIdDEI): bool
    {
        $dossier = $this->getDossier($dossierId);
        if (!$dossier) return false;
        
        // Vérifier que fiche est validée par DEI (fiches.statut_dei)
        // Le dossier peut être en_attente (nouveau) ou valide (déjà validé)
        // On fait la vérification sur fiches.statut_dei
        if (($dossier['statut_dei'] ?? '') !== 'valide') {
            // Si la fiche n'est pas encore validée par DEI, on refuse
            throw new Exception("La fiche n'est pas validée par DEI");
        }
        
        // Générer acte de nomination
        $acteHtml = $this->genererActeNomination($dossierId);
        if (!$acteHtml) {
            throw new Exception("Erreur génération acte de nomination");
        }
        
        // Mettre à jour dossier : marquer comme validé_dei
        $stmt = $this->pdo->prepare(
            "UPDATE vacataire_dossier 
             SET statut_dossier = 'validee_dei', statut_dei = 'valide',
                 acte_nomination_html = ?,
                 validated_by_dei_at = NOW(),
                 validated_by_dei_user_id = ?
             WHERE id = ?"
        );
        return $stmt->execute([$acteHtml, $userIdDEI, $dossierId]);
    }
    
    /**
     * Rejeter dossier (DEI)
     */
    public function rejeterDEI(int $dossierId, int $userIdDEI, string $motif = ''): bool
    {
        $stmt = $this->pdo->prepare(
            "UPDATE vacataire_dossier 
             SET statut_dossier = 'rejete', statut_dei = 'rejete',
                 motif_rejet = ?, validated_by_dei_user_id = ?
             WHERE id = ?"
        );
        return $stmt->execute([$motif, $userIdDEI, $dossierId]);
    }
    
    /**
     * Générer acte nomination HTML (DEI uniquement)
     * 
     * UTILISE ActeNominationGenerator pour modèle officiel UJKZ
     */
    public function genererActeNomination(int $dossierId): string
    {
        $dossier = $this->getDossier($dossierId);
        if (!$dossier) return '';
        
        // Récupérer établissement
        $etablissement = $this->getEtablissement((int)$dossier['etab_beneficiaire_fiche']);
        $estabNom = $etablissement['nom'] ?? '';
        
        // Utiliser le générateur d'acte officiel UJKZ
        $generator = new ActeNominationGenerator($this->pdo);
        return $generator->genererActe([
            'nom' => $dossier['nom'],
            'prenom' => $dossier['prenom'],
            'grade' => $dossier['grade'],
            'diplome' => $dossier['diplome'],
            'specialite' => $dossier['specialite'] ?? '',
        ], $dossier['annee_academique'], $estabNom);
    }
    
    /**
     * Sauvegarder demande vacation
     */
    public function sauvegarderDemandeVacation(int $ficheId, string $htmlDemande): bool
    {
        $stmt = $this->pdo->prepare(
            "UPDATE fiches SET demande_vacation_html = ? WHERE id = ?"
        );
        return $stmt->execute([$htmlDemande, $ficheId]);
    }
    
    /**
     * Ajouter document au dossier
     */
    public function ajouterDocument(int $dossierId, string $type, string $nomFichier, 
                                   string $cheminFichier, ?int $userIdUpload = null): bool
    {
        $taille = file_exists($cheminFichier) ? (int)(filesize($cheminFichier) / 1024) : 0;
        
        $stmt = $this->pdo->prepare(
            "INSERT INTO vacataire_dossier_documents 
             (dossier_id, type, nom_fichier, chemin_fichier, taille_ko, uploaded_by)
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        return $stmt->execute([$dossierId, $type, $nomFichier, $cheminFichier, $taille, $userIdUpload]);
    }
    
    /**
     * Récupérer tous les dossiers VACATAIRE en attente (pour DEI)
     */
    public function getDossiersPendingDEI(): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT vd.*, f.*, e.nom, e.prenom, e.grade, e.diplome, e.specialite,
                    etab.id as etab_id, etab.nom as etab_nom
             FROM vacataire_dossier vd
             JOIN fiches f ON vd.fiche_id = f.id
             JOIN enseignants e ON vd.enseignant_id = e.id
             LEFT JOIN etablissements etab ON f.etab_beneficiaire_fiche = etab.id
             WHERE vd.statut_dei IN ('en_attente', 'valide')
             ORDER BY vd.created_at DESC"
        );
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Récupérer dossiers VACATAIRE d'un enseignant
     */
    public function getDossiersByEnseignant(int $enseignantId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT vd.*, f.*, e.nom, e.prenom, e.grade, e.diplome, e.specialite,
                    etab.id as etab_id, etab.nom as etab_nom
             FROM vacataire_dossier vd
             JOIN fiches f ON vd.fiche_id = f.id
             JOIN enseignants e ON vd.enseignant_id = e.id
             LEFT JOIN etablissements etab ON f.etab_beneficiaire_fiche = etab.id
             WHERE vd.enseignant_id = ?
             ORDER BY vd.created_at DESC"
        );
        $stmt->execute([$enseignantId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Créer un dossier VACATAIRE après validation DEI de la fiche
     * 
     * WORKFLOW :
     * 1. Fiche VACATAIRE validée par DEI
     * 2. Créer automatiquement le dossier VACATAIRE
     * 3. Générer demande vacation
     * 4. En attente de validation DEI pour l'acte
     */
    public function createDossierAfterValidation(int $ficheId, int $enseignantId): int
    {
        // Vérifier pas de dossier existant
        $existing = $this->getDossierByFicheId($ficheId);
        if ($existing) {
            return (int)$existing['id'];
        }
        
        // Récupérer données fiche pour demande vacation
        $ficheStmt = $this->pdo->prepare(
            "SELECT f.*, e.nom, e.prenom, e.grade, e.diplome, e.specialite, e.telephone,
                    etab.nom as etab_nom, d.nom as dept_nom
             FROM fiches f
             LEFT JOIN enseignants e ON f.enseignant_id = e.id
             LEFT JOIN etablissements etab ON f.etab_beneficiaire_fiche = etab.id
             LEFT JOIN departements d ON f.dept_beneficiaire_fiche = d.id
             WHERE f.id = ? LIMIT 1"
        );
        $ficheStmt->execute([$ficheId]);
        $ficheData = $ficheStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$ficheData) {
            throw new Exception("Fiche non trouvée pour création dossier");
        }
        
        // Créer le dossier VACATAIRE
        // ✓ Utiliser annee_academique de la FICHE (source de vérité)
        $stmt = $this->pdo->prepare(
            "INSERT INTO vacataire_dossier 
             (fiche_id, enseignant_id, annee_academique, statut_dossier, statut_dei, created_at)
             VALUES (?, ?, ?, 'complet', 'en_attente', NOW())"
        );
        $stmt->execute([$ficheId, $enseignantId, $ficheData['annee_academique']]);
        $dossierId = (int)$this->pdo->lastInsertId();
        
        // Générer et sauvegarder la demande vacation
        try {
            require_once __DIR__ . '/DemandeVacationGenerator.php';
            $generateur = new DemandeVacationGenerator();
            $htmlDemande = $generateur->genererFicheHTML($ficheId, $enseignantId);
            $this->sauvegarderDemandeVacation($ficheId, $htmlDemande);
        } catch (Exception $e) {
            // Ne pas bloquer si la demande vacation échoue
            error_log("⚠️ Erreur génération demande vacation : " . $e->getMessage());
        }
        
        return $dossierId;
    }
    
    /**
     * Récupérer documents du dossier
     */
    public function getDocuments(int $dossierId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM vacataire_dossier_documents WHERE dossier_id = ? ORDER BY created_at DESC"
        );
        $stmt->execute([$dossierId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Supprimer document
     */
    public function supprimerDocument(int $documentId, int $dossierId): bool
    {
        $stmt = $this->pdo->prepare(
            "DELETE FROM vacataire_dossier_documents WHERE id = ? AND dossier_id = ?"
        );
        return $stmt->execute([$documentId, $dossierId]);
    }
}
?>
