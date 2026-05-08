<?php
/**
 * src/DemandeVacationGenerator.php
 * Génère la fiche de demande de vacation au format HTML/PDF
 * Modèle officiel UJKZ
 */

class DemandeVacationGenerator
{
    private $pdo;
    
    public function __construct($pdo = null)
    {
        $this->pdo = $pdo ?? Database::getInstance();
    }
    
    /**
     * Générer la fiche de demande de vacation au format HTML
     * Pour impression ou PDF
     */
    public function genererFicheHTML(int $ficheId, int $enseignantId): string
    {
        // Récupérer infos fiche + enseignant
        $stmt = $this->pdo->prepare(
            "SELECT 
                f.id, f.numero_fiche, f.cours, f.annee_academique,
                f.etab_beneficiaire_fiche,
                e.nom, e.prenom, e.telephone, e.diplome, e.specialite, e.grade
             FROM fiches f
             JOIN enseignants e ON f.enseignant_id = e.id
             WHERE f.id = ? AND e.id = ? LIMIT 1"
        );
        $stmt->execute([$ficheId, $enseignantId]);
        $fiche = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$fiche) {
            return '<p style="color:red;">Fiche non trouvée</p>';
        }
        
        // Récupérer le nom de l'établissement
        $estabNom = '';
        if (!empty($fiche['etab_beneficiaire_fiche'])) {
            $stmtEstab = $this->pdo->prepare(
                "SELECT nom FROM etablissements WHERE id = ? LIMIT 1"
            );
            $stmtEstab->execute([$fiche['etab_beneficiaire_fiche']]);
            $estab = $stmtEstab->fetch();
            $estabNom = $estab['nom'] ?? '';
        }
        
        // Générer l'HTML
        $html = $this->genererHTML($fiche, $estabNom);
        
        return $html;
    }
    
    /**
     * Générer le HTML de la fiche
     */
    private function genererHTML(array $fiche, string $estabNom): string
    {
        $date = date('d/m/Y');
        $dateArr = explode('/', $date);
        $jour = $dateArr[0];
        $mois = $dateArr[1];
        $annee = $dateArr[2];
        
        $nom = htmlspecialchars($fiche['nom'] ?? '');
        $prenom = htmlspecialchars($fiche['prenom'] ?? '');
        $telephone = htmlspecialchars($fiche['telephone'] ?? '');
        $diplome = htmlspecialchars($fiche['diplome'] ?? '');
        $specialite = htmlspecialchars($fiche['specialite'] ?? '');
        $grade = htmlspecialchars($fiche['grade'] ?? '');
        $cours = htmlspecialchars($fiche['cours'] ?? '');
        $anneeAcad = htmlspecialchars($fiche['annee_academique'] ?? '');
        
        $html = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Fiche de Demande de Vacation</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { 
            font-family: Arial, sans-serif; 
            line-height: 1.6;
            padding: 40px;
            background: white;
        }
        .page { 
            max-width: 800px;
            margin: 0 auto;
            background: white;
            padding: 40px;
            border: 1px solid #ddd;
            min-height: 800px;
        }
        .header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 40px;
            padding-bottom: 20px;
        }
        .header-left {
            flex: 1;
            text-align: center;
        }
        .header-logo {
            width: 100px;
            height: 100px;
            margin: 0 auto;
            padding: 10px;
        }
        .header-text {
            font-size: 12px;
            font-weight: bold;
            text-align: center;
            margin-top: 10px;
        }
        .header-right {
            flex: 1;
            text-align: center;
            font-weight: bold;
        }
        .country {
            font-size: 14px;
            font-weight: bold;
            margin-bottom: 10px;
        }
        .motto {
            font-size: 11px;
            font-weight: bold;
            margin-top: 10px;
        }
        .title {
            text-align: center;
            font-size: 18px;
            font-weight: bold;
            text-decoration: underline;
            margin: 30px 0;
        }
        .content {
            margin: 30px 0;
            line-height: 2;
        }
        .field {
            display: flex;
            margin-bottom: 12px;
            font-size: 12px;
        }
        .field-label {
            font-weight: bold;
            width: 180px;
            flex-shrink: 0;
        }
        .field-value {
            flex: 1;
            border-bottom: 1px dotted #666;
            padding: 0 10px;
        }
        .field-inline {
            display: flex;
            gap: 40px;
            margin-bottom: 12px;
        }
        .field-inline-item {
            flex: 1;
        }
        .btn {
            display: inline-block;
            padding: 8px 16px;
            font-size: 13px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-weight: 600;
            text-decoration: none;
            transition: background-color 0.2s;
        }
        .btn-sm {
            padding: 6px 12px;
            font-size: 12px;
        }
        .btn-primary {
            background-color: #007bff;
            color: white;
        }
        .btn-primary:hover {
            background-color: #0056b3;
        }
        @media print {
            body { padding: 0; background: none; }
            .page { border: none; padding: 0; }
            .no-print { display: none; }
        }
    </style>
</head>
<body>

<div class="page">

<!-- EN-TÊTE SIMPLIFIÉ -->
<div class="header">
    <div class="header-left">
        <div class="header-logo">
            <img src="logo_ujkz.jpg" alt="Logo UJKZ" style="width: 100%; height: 100%; object-fit: contain;">
        </div>
        <div class="header-text">
            UNIVERSITÉ JOSEPH KI-ZERBO
        </div>
    </div>
    <div class="header-right">
        <div class="country">BURKINA FASO</div>
        <div class="motto">La Patrie ou la Mort, nous Vaincrons</div>
    </div>
</div>

<!-- TITRE PRINCIPAL -->
<div class="title">
    FICHE DE DEMANDE DE VACATION
</div>

<!-- CONTENU PRINCIPAL -->
<div class="content">
    <div class="field-inline">
        <div class="field-inline-item">
            <div class="field">
                <div class="field-label"><strong>Nom :</strong></div>
                <div class="field-value">$nom</div>
            </div>
        </div>
        <div class="field-inline-item">
            <div class="field">
                <div class="field-label"><strong>Prénom (s) :</strong></div>
                <div class="field-value">$prenom</div>
            </div>
        </div>
    </div>
    
    <div class="field-inline">
        <div class="field-inline-item">
            <div class="field">
                <div class="field-label"><strong>Titre :</strong></div>
                <div class="field-value">$grade</div>
            </div>
        </div>
        <div class="field-inline-item">
            <div class="field">
                <div class="field-label"><strong>Tél :</strong></div>
                <div class="field-value">$telephone</div>
            </div>
        </div>
    </div>
    
    <div class="field">
        <div class="field-label"><strong>Diplôme (s) :</strong></div>
        <div class="field-value">$diplome</div>
    </div>
    
    <div class="field">
        <div class="field-label"><strong>Spécialités :</strong></div>
        <div class="field-value">$specialite</div>
    </div>
    
    <div class="field">
        <div class="field-label"><strong>Établissement de vacation :</strong></div>
        <div class="field-value">$estabNom</div>
    </div>
    
    <div class="field">
        <div class="field-label"><strong>Année académique :</strong></div>
        <div class="field-value">$anneeAcad</div>
    </div>
    
    <div class="field" style="margin-top: 30px;">
        <div class="field-label"><strong>Enseignements confiés :</strong></div>
        <div class="field-value">$cours</div>
    </div>
</div>

<!-- Bouton Imprimer -->
<div style="text-align: center; margin-top: 40px; padding: 20px; border-top: 1px solid #ddd;" class="no-print">
    <button onclick="window.print()" class="btn btn-sm btn-primary">🖨 Imprimer / PDF</button>
</div>

</div>

</body>
</html>
HTML;

        return $html;
    }
}
?>
