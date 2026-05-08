<?php
/**
 * src/ActeNominationGenerator.php
 * Génère l'acte de nomination selon le modèle officiel UJKZ
 */

class ActeNominationGenerator
{
    private $pdo;
    
    public function __construct($pdo = null)
    {
        $this->pdo = $pdo ?? Database::getInstance();
    }
    
    /**
     * Générer acte de nomination au format HTML
     * Modèle officiel UJKZ basé sur le document de référence
     */
    public function genererActe(array $enseignant, string $anneeAcad, string $etablissement): string
    {
        $nom = htmlspecialchars($enseignant['nom'] ?? '');
        $prenom = htmlspecialchars($enseignant['prenom'] ?? '');
        $grade = htmlspecialchars($enseignant['grade'] ?? '');
        $diplome = htmlspecialchars($enseignant['diplome'] ?? '');
        $specialite = htmlspecialchars($enseignant['specialite'] ?? '');
        $etablissement = htmlspecialchars($etablissement);
        $anneeAcad = htmlspecialchars($anneeAcad);
        
        // Date
        $now = new DateTime();
        $jour = str_pad($now->format('d'), 2, '0', STR_PAD_LEFT);
        $mois = str_pad($now->format('m'), 2, '0', STR_PAD_LEFT);
        $annee = $now->format('Y');
        
        $html = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Acte de Nomination - Vacataire UJKZ</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { 
            font-family: 'Times New Roman', Times, serif;
            line-height: 1.5;
            padding: 40px;
            background: white;
            color: #000;
        }
        .page {
            max-width: 950px;
            margin: 0 auto;
            background: white;
            padding: 60px;
            border: 1px solid #000;
            min-height: 1200px;
        }
        .header {
            text-align: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            font-size: 10px;
            line-height: 1.4;
        }
        .logo-section {
            text-align: center;
            margin-bottom: 15px;
        }
        .logo {
            display: inline-block;
            width: 90px;
            height: 90px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 12px;
            text-align: center;
            padding: 0;
        }
        .logo img {
            max-width: 100%;
            max-height: 100%;
            object-fit: contain;
        }
        .header-left {
            float: left;
            width: 37.5%;
            text-align: left;
            font-weight: bold;
        }
        .header-right {
            float: right;
            width: 37.5%;
            text-align: right;
            font-weight: bold;
        }
        .clear { clear: both; }
        .reference {
            text-align: center;
            margin: 20px 0;
            font-size: 12px;
            font-weight: bold;
        }
        .visa {
            text-align: center;
            font-size: 12px;
            font-weight: bold;
            margin: 30px 0;
            text-decoration: underline;
        }
        .vu-section {
            font-size: 11px;
            margin-bottom: 6px;
            line-height: 1.4;
            text-align: justify;
        }
        .vu-label {
            font-weight: bold;
            display: inline;
        }
        .sur-proposition {
            font-size: 11px;
            margin: 25px 0;
            font-style: italic;
            text-align: left;
        }
        .decide {
            text-align: center;
            font-size: 14px;
            font-weight: bold;
            margin: 35px 0;
            text-decoration: underline;
        }
        .article {
            font-size: 11px;
            margin-bottom: 12px;
            line-height: 1.6;
            text-align: justify;
        }
        .article-label {
            font-weight: bold;
        }
        .date-location {
            text-align: center;
            margin-top: 40px;
            font-size: 11px;
        }
        .signature-section {
            margin-top: 80px;
            text-align: center;
        }
        .signature-title {
            font-weight: bold;
            font-size: 11px;
            margin-bottom: 3px;
        }
        .signature-subtitle {
            font-style: italic;
            font-size: 10px;
            margin-bottom: 70px;
        }
        .signature-name {
            font-weight: bold;
            font-size: 11px;
            margin-bottom: 3px;
            text-decoration: underline;
        }
        .distribution {
            margin-top: 40px;
            padding-top: 20px;
            font-size: 9px;
            line-height: 1.5;
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
        .no-print {
            text-align: center;
            margin-top: 40px;
            padding: 20px;
        }
        @media print {
            body { padding: 0; background: none; }
            .page { border: none; padding: 40px; }
            .no-print { display: none; }
        }
    </style>
</head>
<body>

<div class="page">

<!-- LOGO ET EN-TÊTE OFFICIEL -->
<div class="header">
    <div class="header-left">
        MINISTÈRE DE L'ENSEIGNEMENT<br>
        SUPÉRIEUR, DE LA RECHERCHE<br>
        ET DE L'INNOVATION<br>
        <br>
        SECRÉTARIAT GÉNÉRAL<br>
        <br>
        UNIVERSITÉ JOSEPH KI-ZERBO<br>
        <br>
        PRÉSIDENCE<br>
        <br>
        03 BP 7021 OUAGADOUGOU 03<br>
        Tél. : 25 30 70 64/65<br>
        Fax : (226) 25 30 72 42<br>
        Télex : 5270 BF
    </div>
    <div style="float: left; width: 25%; text-align: center; vertical-align: middle; display: flex; align-items: center; justify-content: center; height: 200px;">
        <div class="logo">
            <img src="logo_ujkz.jpg" alt="Logo UJKZ">
        </div>
    </div>
    <div class="header-right">
        BURKINA FASO<br>
        <br>
        La Patrie ou la Mort,<br>
        nous Vaincrons
    </div>
    <div class="clear"></div>
</div>

<!-- RÉFÉRENCE -->
<div class="reference">
    Décision n°____/______/MESRI/SG/UJKZ/P<br>
    portant nomination d'un vacataire<br>
    pour l'année universitaire $anneeAcad
</div>

<!-- VISA -->
<div class="visa">LE PRÉSIDENT DE L'UNIVERSITÉ JOSEPH KI-ZERBO</div>

<!-- VISAS -->
<div style="margin: 25px 0; font-size: 11px;">
    <div class="vu-section"><span class="vu-label">Vu</span> la Constitution ;</div>
    <div class="vu-section"><span class="vu-label">Vu</span> la Charte de la Transition du 14 octobre 2022 et son modificatif du 25 mai 2024 ;</div>
    <div class="vu-section"><span class="vu-label">Vu</span> les décrets relatifs à la nomination des enseignants vacataires ;</div>
    <div class="vu-section"><span class="vu-label">Vu</span> les statuts particuliers de l'Université Joseph KI-ZERBO ;</div>
    <div class="vu-section"><span class="vu-label">Vu</span> la loi n°013-2007/AN du 30 juillet 2017 portant loi d'orientation de l'éducation ;</div>
    <div class="vu-section"><span class="vu-label">Vu</span> la loi n°025/2010/AN du 18 mai 2010 portant régime juridique applicable aux emplois des enseignants-chercheurs ;</div>
    <div class="vu-section"><span class="vu-label">Vu</span> l'arrêté n°2004-044/MESSRS/SG/UO/P du 15 juillet 2004 portant modalités de recrutement et d'emploi d'enseignants vacataires ;</div>
    <div class="vu-section"><span class="vu-label">Vu</span> la circulaire n°2017-00554/MINEFID/SG/DGI/DLC du 07 mars 2017 relative à l'application de la retenue à la source de 2% ;</div>
    <div class="vu-section"><span class="vu-label">Vu</span> la demande de vacation enregistrée ;</div>
    <div class="vu-section"><span class="vu-label">Vu</span> le dossier pédagogique de l'enseignant proposé ;</div>
</div>

<!-- SUR PROPOSITION -->
<div class="sur-proposition">
    <strong>Sur proposition</strong> du Directeur de l'Établissement d'enseignement.
</div>

<!-- DECIDE -->
<div class="decide">DÉCIDE</div>

<!-- ARTICLES -->
<div class="article">
    <span class="article-label">Article 1 :</span> Est nommé(e) à titre de <strong>VACATAIRE</strong> à l'Université Joseph KI-ZERBO pour l'année académique <strong>$anneeAcad</strong>, 
    <strong>$prenom $nom</strong>, titulaire d'un <strong>$grade</strong>, diplômé(e) en <strong>$diplome</strong>, spécialiste en <strong>$specialite</strong>, 
    pour enseigner à <strong>$etablissement</strong>. L'intéressé(e) dispensera un nombre d'heures de cours théoriques, travaux dirigés et travaux pratiques à déterminer par l'établissement.
</div>

<div class="article">
    <span class="article-label">Article 2 :</span> L'enseignant ainsi nommé(e) est tenu(e) de se conformer à tous les textes régissant les personnels de l'Enseignement Supérieur et de la Recherche Scientifique.
</div>

<div class="article">
    <span class="article-label">Article 3 :</span> Conformément aux dispositions de la lettre circulaire n°2017-00554/MINEFID/SG/DGI/DLC du 07 mars 2017, l'intéressé(e) est informé(e) qu'il lui sera applicable une retenue à la source de 2% de ses frais de vacation.
</div>

<div class="article">
    <span class="article-label">Article 4 :</span> Le Vice-Président chargé des Enseignements, des Innovations pédagogiques et de la Professionnalisation, le Directeur de l'Établissement d'enseignement, 
    la Direction de l'Administration et des Finances et l'Agent Comptable de l'Université Joseph KI-ZERBO sont chargés, chacun en ce qui le concerne, 
    de l'exécution de la présente décision qui sera enregistrée, publiée et communiquée partout où besoin sera.
</div>

<!-- DATE ET LIEU -->
<div class="date-location">
    Ouagadougou, le $jour/$mois/$annee
</div>

<!-- SIGNATURE -->
<div class="signature-section">
    <div class="signature-title">P/le Président et par délégation</div>
    <div class="signature-subtitle">
        Le Vice-Président chargé des Enseignements,<br>
        des Innovations pédagogiques et de la<br>
        Professionnalisation (VP EIP)
    </div>
    <div style="margin-top: 80px; padding-top: 10px;">
        <div class="signature-name">_________________________</div>
        <div style="font-size: 10px; margin-top: 5px;">Signature et Cachet</div>
    </div>
</div>

<!-- DISTRIBUTION -->
<div class="distribution">
    <strong>Distribution :</strong><br>
    • SG (Secrétariat Général)<br>
    • DAF (Direction Administrative et Financière)<br>
    • AC (Agent Comptable)<br>
    • DCMEF/UJKZ<br>
    • SSFI (Service de Suivi Financier)<br>
    • Établissement d'enseignement concerné<br>
    • Intéressé(e)
</div>

<!-- Bouton Imprimer -->
<div class="no-print">
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
