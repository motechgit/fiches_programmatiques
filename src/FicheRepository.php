<?php
declare(strict_types=1);

class FicheRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::getInstance();
    }

    // ── Trouver ou créer un enseignant ───────────────────────
    public function upsertEnseignant(
        string $matricule,
        string $nom,
        string $departement,
        string $email,
        string $token,
        array  $extra = []
    ): int {
        $stmt = $this->pdo->prepare(
            "SELECT id FROM enseignants WHERE matricule = ?"
        );
        $stmt->execute([strtoupper(trim($matricule))]);
        $existing = $stmt->fetch();

        if ($existing) {
            $this->pdo->prepare(
                "UPDATE enseignants
                 SET nom=?, prenom=?, diplome=?, departement=?, email=?,
                     type_enseignant=?, grade=?, date_nomination=?,
                     volume_statutaire=?, abattement=?, motif_abattement=?,
                     volume_apres_abatt=?, etab_rattachement=?, etab_administratif=?,
                     etab_beneficiaire=?, mois_execution=?, token=?,
                     telephone=?, specialite=?,
                     fichier_diplome=IF(?<>'',?,fichier_diplome),
                     fichier_nomination=IF(?<>'',?,fichier_nomination)
                 WHERE matricule=?"
            )->execute([
                $nom,
                $extra['prenom']              ?? '',
                $extra['diplome']             ?? '',
                $departement, $email,
                $extra['type_enseignant']     ?? 'permanent',
                $extra['grade']              ?? '',
                $extra['date_nomination']    ?: null,
                $extra['volume_statutaire']  ?: null,
                $extra['abattement']         ?: null,
                $extra['motif_abattement']   ?? '',
                $extra['volume_apres_abatt'] ?: null,
                $extra['etab_rattachement']  ?? '',
                $extra['etab_administratif'] ?? '',
                $extra['etab_beneficiaire']  ?? '',
                $extra['mois_execution']     ?? '',
                $token,
                $extra['telephone']          ?? '',
                $extra['specialite']         ?? '',
                $extra['fichier_diplome']    ?? '', $extra['fichier_diplome']    ?? '',
                $extra['fichier_nomination'] ?? '', $extra['fichier_nomination'] ?? '',
                strtoupper(trim($matricule)),
            ]);
            return (int) $existing['id'];
        }

        $this->pdo->prepare(
            "INSERT INTO enseignants
             (matricule,nom,prenom,diplome,departement,email,token,
              type_enseignant,grade,date_nomination,
              volume_statutaire,abattement,motif_abattement,
              volume_apres_abatt,etab_rattachement,etab_administratif,
              etab_beneficiaire,mois_execution,telephone,specialite,
              fichier_diplome,fichier_nomination)
             VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
        )->execute([
            strtoupper(trim($matricule)), $nom,
            $extra['prenom']              ?? '',
            $extra['diplome']             ?? '',
            $departement, $email, $token,
            $extra['type_enseignant']     ?? 'permanent',
            $extra['grade']              ?? '',
            $extra['date_nomination']    ?: null,
            $extra['volume_statutaire']  ?: null,
            $extra['abattement']         ?: null,
            $extra['motif_abattement']   ?? '',
            $extra['volume_apres_abatt'] ?: null,
            $extra['etab_rattachement']  ?? '',
            $extra['etab_administratif'] ?? '',
            $extra['etab_beneficiaire']  ?? '',
            $extra['mois_execution']     ?? '',
            $extra['telephone']          ?? '',
            $extra['specialite']         ?? '',
            $extra['fichier_diplome']    ?? '',
            $extra['fichier_nomination'] ?? '',
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    // ── Créer une fiche ──────────────────────────────────────
    public function createFiche(int $enseignantId, array $data): int
    {
        $this->pdo->prepare(
            "INSERT INTO fiches
             (enseignant_id,cours,code_ue,code,parcours,ntc,
              niveau,semestre,volume_cm,volume_td,volume_tp,objectifs,evaluation,
              annee_academique,is_encadrement,type_workflow,
              etab_beneficiaire_fiche,dept_beneficiaire_fiche)
             VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
        )->execute([
            $enseignantId,
            $data['cours'],
            $data['code']      ?? '',
            $data['code']      ?? '',
            $data['parcours']  ?? '',
            $data['ntc']       ?? '',
            $data['niveau'],
            $data['semestre'],
            $data['volume_cm'],
            $data['volume_td'],
            $data['volume_tp'] ?? 0,
            $data['objectifs'],
            $data['evaluation'],
            $data['annee_academique'],
            $data['is_encadrement']           ?? 0,
            $data['type_workflow']            ?? 'IESR_UJKZ',
            (int)($data['etab_beneficiaire_fiche'] ?? 0),
            (int)($data['dept_beneficiaire_fiche'] ?? 0),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    // ── Modifier une fiche (statut en_attente uniquement) ────
    public function updateFiche(int $ficheId, int $enseignantId, array $data): bool
    {
        $stmt = $this->pdo->prepare(
            "UPDATE fiches SET
               cours=?, code_ue=?, code=?, parcours=?, ntc=?,
               niveau=?, semestre=?, volume_cm=?, volume_td=?, volume_tp=?,
               objectifs=?, evaluation=?, is_encadrement=?, type_workflow=?,
               etab_beneficiaire_fiche=?, dept_beneficiaire_fiche=?,
               modifie_le=NOW(),
               nb_modifications = nb_modifications + 1
             WHERE id=? AND enseignant_id=?"
        );
        $stmt->execute([
            $data['cours'],
            $data['code_ue'],
            $data['code']      ?? '',
            $data['parcours']  ?? '',
            $data['ntc']       ?? '',
            $data['niveau'],
            $data['semestre'],
            $data['volume_cm'],
            $data['volume_td'],
            $data['volume_tp'] ?? 0,
            $data['objectifs'],
            $data['evaluation'],
            $data['is_encadrement']          ?? 0,
            $data['type_workflow']           ?? 'IESR_UJKZ',
            (int)($data['etab_beneficiaire_fiche'] ?? 0),
            (int)($data['dept_beneficiaire_fiche'] ?? 0),
            $ficheId,
            $enseignantId,
        ]);
        return $stmt->rowCount() > 0;
    }

    // ── Trouver un enseignant par token ──────────────────────
    public function findByToken(string $token): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM enseignants WHERE token = ? LIMIT 1"
        );
        $stmt->execute([$token]);
        $row = $stmt->fetch();
        if (!$row) return null;
        // Garantir les colonnes optionnelles (compat avant migration 006)
        $row += ['prenom'=>'','diplome'=>'','mois_execution'=>''];
        return $row;
    }

    // ── Trouver un enseignant par matricule ──────────────────
    public function findByMatricule(string $matricule): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM enseignants WHERE matricule = ? LIMIT 1"
        );
        $stmt->execute([strtoupper(trim($matricule))]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    // ── Fiches d'un enseignant ───────────────────────────────
    public function getFichesByEnseignant(int $enseignantId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM fiches WHERE enseignant_id = ? ORDER BY submitted_at DESC"
        );
        $stmt->execute([$enseignantId]);
        return $stmt->fetchAll();
    }

    // ── Statistiques d'un enseignant ─────────────────────────
    public function getStatsByEnseignant(int $enseignantId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT
               COUNT(*) AS total,
               SUM(CASE WHEN statut='en_attente' THEN 1 ELSE 0 END) AS en_attente,
               SUM(CASE WHEN statut='validee'    THEN 1 ELSE 0 END) AS validee,
               SUM(CASE WHEN statut='rejetee'    THEN 1 ELSE 0 END) AS rejetee
             FROM fiches WHERE enseignant_id = ?"
        );
        $stmt->execute([$enseignantId]);
        return $stmt->fetch() ?: ['total'=>0,'en_attente'=>0,'validee'=>0,'rejetee'=>0];
    }

    // ── Obtenir une fiche (vérification propriétaire) ────────
    public function getFicheByIdAndEnseignant(int $ficheId, int $enseignantId): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM fiches WHERE id = ? AND enseignant_id = ? LIMIT 1"
        );
        $stmt->execute([$ficheId, $enseignantId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }
}
