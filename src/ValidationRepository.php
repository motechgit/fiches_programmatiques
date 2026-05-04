<?php
// ============================================================
// src/ValidationRepository.php — Workflow de validation 4 niveaux
// ============================================================
declare(strict_types=1);

class ValidationRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::getInstance();
    }

    // ── Valider ou rejeter une fiche ─────────────────────────
    public function enregistrerDecision(
        int    $ficheId,
        int    $utilisateurId,
        string $role,
        string $decision,    // 'valide' | 'rejete'
        string $motif = ''
    ): bool {
        // Insérer la décision
        $this->pdo->prepare(
            "INSERT INTO validations_fiche (fiche_id, utilisateur_id, role, decision, motif_rejet)
             VALUES (?, ?, ?, ?, ?)"
        )->execute([$ficheId, $utilisateurId, $role, $decision, $motif ?: null]);

        // Mettre à jour la colonne de statut correspondante
        if ($role === 'chef_dept')        { $col = 'statut_chef'; }
        elseif ($role === 'directeur_adjoint') { $col = 'statut_dir_adj'; }
        elseif ($role === 'directeur')    { $col = 'statut_dir'; }
        elseif ($role === 'dei')          { $col = 'statut_dei'; }
        elseif ($role === 'vp_eip')       { $col = 'statut_vp_eip'; }
        else { $col = null; }
        if (!$col) return false;

        $newStatut = $decision === 'valide' ? 'valide' : 'rejete';
        $this->pdo->prepare("UPDATE fiches SET `$col` = ? WHERE id = ?")
            ->execute([$newStatut, $ficheId]);

        // Si DEI vient de valider une fiche VACATAIRE → mettre statut_vp_eip en attente
        if ($role === 'dei' && $decision === 'valide') {
            $stmtWf = $this->pdo->prepare("SELECT type_workflow FROM fiches WHERE id = ? LIMIT 1");
            $stmtWf->execute([$ficheId]);
            $wfRow = $stmtWf->fetch();
            if ($wfRow && ($wfRow['type_workflow'] ?? '') === 'VACATAIRE') {
                $this->pdo->prepare("UPDATE fiches SET statut_vp_eip = 'en_attente' WHERE id = ?")
                    ->execute([$ficheId]);
            }
        }

        // Calculer le statut global
        $this->recalculerStatutGlobal($ficheId);

        return true;
    }

    // ── Recalcul statut global selon le type de workflow ────
    private function recalculerStatutGlobal(int $ficheId): void
    {
        $stmt = $this->pdo->prepare(
            "SELECT type_workflow, statut_chef, statut_dir_adj, statut_dir,
                    statut_dei, statut_vp_eip FROM fiches WHERE id = ?"
        );
        $stmt->execute([$ficheId]);
        $f = $stmt->fetch();
        if (!$f) return;

        $wf = $f['type_workflow'] ?? 'IESR_UJKZ';

        // Une étape rejetée → statut global rejetee (immédiat)
        $statuts = [$f['statut_chef'], $f['statut_dir_adj'], $f['statut_dir'], $f['statut_dei']];
        if ($wf === 'VACATAIRE') {
            $statuts[] = $f['statut_vp_eip'];
        }
        if (in_array('rejete', $statuts, true)) {
            $global = 'rejetee';
        } elseif ($wf === 'VACATAIRE') {
            // Validée seulement quand VP EIP a validé
            $global = ($f['statut_vp_eip'] === 'valide') ? 'validee' : 'en_attente';
        } else {
            // IESR_UJKZ et IESR_HORS : validée quand DEI a validé
            $global = ($f['statut_dei'] === 'valide') ? 'validee' : 'en_attente';
        }

        $this->pdo->prepare("UPDATE fiches SET statut = ? WHERE id = ?")
            ->execute([$global, $ficheId]);
    }

    // ── Historique des validations d'une fiche ───────────────
    public function getHistorique(int $ficheId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT v.*, u.nom AS valideur_nom, v.role AS etape_role
             FROM validations_fiche v
             JOIN utilisateurs u ON u.id = v.utilisateur_id
             WHERE v.fiche_id = ?
             ORDER BY v.created_at ASC"
        );
        $stmt->execute([$ficheId]);
        return $stmt->fetchAll();
    }

    // ── Fiche avec données enseignant ────────────────────────
    public function getFicheComplete(int $ficheId): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT f.*, e.nom AS ens_nom, e.matricule, e.departement,
                    e.etab_beneficiaire, e.etab_rattachement, e.etab_administratif,
                    e.grade, e.type_enseignant, e.token
             FROM fiches f
             JOIN enseignants e ON e.id = f.enseignant_id
             WHERE f.id = ? LIMIT 1"
        );
        $stmt->execute([$ficheId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    // ── Fiches selon le scope de l'utilisateur ───────────────
    // $filters : ['statut'=>..., 'departement'=>..., 'etab'=>..., 'q'=>...]
    public function getFichesPourUtilisateur(string $roleFilter = 'tous', array $filters = []): array
    {
        [$scopeWhere, $scopeParams] = Auth::ficheScope();

        $extraWhere  = '';
        $extraParams = [];

        // Filtre statut par étape
        if ($roleFilter !== 'tous') {
            $ur = Auth::userRole();
            if ($ur === 'chef_dept')        { $col = 'f.statut_chef'; }
            elseif ($ur === 'directeur_adjoint') { $col = 'f.statut_dir_adj'; }
            elseif ($ur === 'directeur')    { $col = 'f.statut_dir'; }
            elseif ($ur === 'dei')          { $col = 'f.statut_dei'; }
            elseif ($ur === 'vp_eip')       { $col = 'f.statut_vp_eip'; }
            else { $col = 'f.statut'; }
            $extraWhere .= " AND $col = ?";
            $extraParams[] = $roleFilter;
        }

        // Filtre département
        if (!empty($filters['departement'])) {
            $extraWhere  .= ' AND e.departement = ?';
            $extraParams[] = $filters['departement'];
        }

        // Filtre établissement bénéficiaire
        if (!empty($filters['etab'])) {
            $extraWhere  .= ' AND e.etab_beneficiaire = ?';
            $extraParams[] = $filters['etab'];
        }

        // Recherche texte libre (enseignant ou cours)
        if (!empty($filters['q'])) {
            $extraWhere  .= ' AND (e.nom LIKE ? OR e.matricule LIKE ? OR f.cours LIKE ?)';
            $like = '%' . $filters['q'] . '%';
            $extraParams = array_merge($extraParams, [$like, $like, $like]);
        }

        $stmt = $this->pdo->prepare(
            "SELECT f.*, e.nom AS ens_nom, e.matricule, e.departement,
                    e.etab_beneficiaire, e.grade, e.id AS enseignant_id,
                    (SELECT COUNT(*) FROM preuves p WHERE p.fiche_id = f.id) AS nb_preuves
             FROM fiches f
             JOIN enseignants e ON e.id = f.enseignant_id
             WHERE 1=1 $scopeWhere $extraWhere
             ORDER BY e.etab_beneficiaire ASC, e.departement ASC, f.submitted_at DESC"
        );
        $stmt->execute(array_merge($scopeParams, $extraParams));
        return $stmt->fetchAll();
    }

    // ── Stats volumes CM/TD par département et établissement ──
    public function getStatsVolumes(): array
    {
        [$sw, $sp] = Auth::ficheScope();

        $byDept = $this->pdo->prepare(
            "SELECT e.departement,
                    SUM(f.volume_cm) AS cm_soumis,
                    SUM(f.volume_td) AS td_soumis,
                    SUM(CASE WHEN f.statut='validee' THEN f.volume_cm ELSE 0 END) AS cm_valide,
                    SUM(CASE WHEN f.statut='validee' THEN f.volume_td ELSE 0 END) AS td_valide,
                    COUNT(*) AS nb_fiches
             FROM fiches f JOIN enseignants e ON e.id = f.enseignant_id
             WHERE 1=1 $sw
             GROUP BY e.departement ORDER BY e.departement"
        );
        $byDept->execute($sp);

        $byEtab = $this->pdo->prepare(
            "SELECT e.etab_beneficiaire,
                    SUM(f.volume_cm) AS cm_soumis,
                    SUM(f.volume_td) AS td_soumis,
                    SUM(CASE WHEN f.statut='validee' THEN f.volume_cm ELSE 0 END) AS cm_valide,
                    SUM(CASE WHEN f.statut='validee' THEN f.volume_td ELSE 0 END) AS td_valide,
                    COUNT(*) AS nb_fiches
             FROM fiches f JOIN enseignants e ON e.id = f.enseignant_id
             WHERE 1=1 $sw
             GROUP BY e.etab_beneficiaire ORDER BY e.etab_beneficiaire"
        );
        $byEtab->execute($sp);

        return ['dept' => $byDept->fetchAll(), 'etab' => $byEtab->fetchAll()];
    }

    // ── Stats pour le tableau de bord utilisateur ────────────
    public function getStats(): array
    {
        [$sw, $sp] = Auth::ficheScope();
        $ur2 = Auth::userRole();
        if ($ur2 === 'chef_dept')        { $col = 'f.statut_chef'; }
        elseif ($ur2 === 'directeur_adjoint') { $col = 'f.statut_dir_adj'; }
        elseif ($ur2 === 'directeur')    { $col = 'f.statut_dir'; }
        elseif ($ur2 === 'dei')          { $col = 'f.statut_dei'; }
        elseif ($ur2 === 'vp_eip')       { $col = 'f.statut_vp_eip'; }
        else { $col = 'f.statut'; }
        $stmt = $this->pdo->prepare(
            "SELECT
               COUNT(*) AS total,
               SUM(CASE WHEN $col = 'en_attente' THEN 1 ELSE 0 END) AS a_traiter,
               SUM(CASE WHEN $col = 'valide'     THEN 1 ELSE 0 END) AS validees,
               SUM(CASE WHEN $col = 'rejete'     THEN 1 ELSE 0 END) AS rejetees,
               SUM(CASE WHEN f.statut = 'validee' THEN 1 ELSE 0 END) AS completement_validees
             FROM fiches f JOIN enseignants e ON e.id = f.enseignant_id
             WHERE 1=1 $sw"
        );
        $stmt->execute($sp);
        return $stmt->fetch() ?: [];
    }


    // ── Enseignants avec leurs fiches groupées pour le portail ──
    public function getEnseignantsPourPortail(array $filters = []): array
    {
        [$sw, $sp] = Auth::ficheScope();

        $extraWhere  = '';
        $extraParams = [];

        if (!empty($filters['departement'])) {
            $extraWhere  .= ' AND e.departement = ?';
            $extraParams[] = $filters['departement'];
        }
        if (!empty($filters['etab'])) {
            $extraWhere  .= ' AND e.etab_beneficiaire = ?';
            $extraParams[] = $filters['etab'];
        }
        if (!empty($filters['q'])) {
            $extraWhere  .= ' AND (e.nom LIKE ? OR e.matricule LIKE ?)';
            $like = '%' . $filters['q'] . '%';
            $extraParams[] = $like; $extraParams[] = $like;
        }
        // Construire la clause HAVING pour le filtre statut (après GROUP BY)
        $havingClause  = '';
        $havingParams  = [];
        if (!empty($filters['statut']) && $filters['statut'] !== 'tous') {
            $ur  = Auth::userRole();
            $stV = $filters['statut'];
            if ($stV === 'en_attente') {
                // "À traiter" : au moins une fiche de l'enseignant est en attente à l'étape du rôle
                if ($ur === 'chef_dept')             { $havingClause = "HAVING MAX(CASE WHEN f.statut_chef    = 'en_attente' THEN 1 ELSE 0 END) = 1"; }
                elseif ($ur === 'directeur_adjoint') { $havingClause = "HAVING MAX(CASE WHEN f.statut_dir_adj = 'en_attente' THEN 1 ELSE 0 END) = 1"; }
                elseif ($ur === 'directeur')         { $havingClause = "HAVING MAX(CASE WHEN f.statut_dir     = 'en_attente' THEN 1 ELSE 0 END) = 1"; }
                elseif ($ur === 'dei')               { $havingClause = "HAVING MAX(CASE WHEN f.statut_dei     = 'en_attente' THEN 1 ELSE 0 END) = 1"; }
                elseif ($ur === 'vp_eip')            { $havingClause = "HAVING MAX(CASE WHEN f.statut_vp_eip  = 'en_attente' THEN 1 ELSE 0 END) = 1"; }
                else { $havingClause = "HAVING SUM(CASE WHEN f.statut = 'en_attente' THEN 1 ELSE 0 END) > 0"; }
            } elseif ($stV === 'valide' || $stV === 'validee') {
                // "Validées" : l'étape du validateur est validée pour au moins une fiche
                // (le validateur a fait son travail, même si le circuit global continue)
                if ($ur === 'chef_dept')             { $havingClause = "HAVING MAX(CASE WHEN f.statut_chef    = 'valide' THEN 1 ELSE 0 END) = 1"; }
                elseif ($ur === 'directeur_adjoint') { $havingClause = "HAVING MAX(CASE WHEN f.statut_dir_adj = 'valide' THEN 1 ELSE 0 END) = 1"; }
                elseif ($ur === 'directeur')         { $havingClause = "HAVING MAX(CASE WHEN f.statut_dir     = 'valide' THEN 1 ELSE 0 END) = 1"; }
                elseif ($ur === 'dei')               { $havingClause = "HAVING MAX(CASE WHEN f.statut_dei     = 'valide' THEN 1 ELSE 0 END) = 1"; }
                elseif ($ur === 'vp_eip')            { $havingClause = "HAVING MAX(CASE WHEN f.statut_vp_eip  = 'valide' THEN 1 ELSE 0 END) = 1"; }
                else { $havingClause = "HAVING SUM(CASE WHEN f.statut = 'validee' THEN 1 ELSE 0 END) > 0"; }
            } elseif ($stV === 'rejete' || $stV === 'rejetee') {
                // "Rejetées" : au moins une fiche rejetée
                $havingClause = "HAVING SUM(CASE WHEN f.statut = 'rejetee' THEN 1 ELSE 0 END) > 0";
            }
        }

        // ── Stratégie : JOIN sur un sous-select filtré par le scope ──────────────
        // Cela garantit que les agrégats (COUNT, MAX) ne portent que sur les fiches
        // dans le périmètre du validateur — crucial pour HORS/VACATAIRE où chaque
        // fiche peut avoir un étab/dept bénéficiaire différent.
        // $sw peut référencer e.departement (IESR_UJKZ) ou f.dept_beneficiaire_fiche (HORS/VAC)
        // On joint les deux tables dans la requête principale et on applique $sw en WHERE
        $stmt = $this->pdo->prepare(
            "SELECT e.id, e.matricule, e.nom, e.prenom, e.diplome, e.departement,
                    e.etab_beneficiaire, e.etab_rattachement,
                    e.grade, e.type_enseignant,
                    e.volume_statutaire, e.abattement, e.motif_abattement,
                    e.volume_apres_abatt, e.date_nomination,
                    COUNT(f.id) AS nb_fiches,
                    SUM(CASE WHEN f.statut='en_attente' THEN 1 ELSE 0 END) AS nb_attente,
                    SUM(CASE WHEN f.statut='validee'    THEN 1 ELSE 0 END) AS nb_validee,
                    MAX(CASE WHEN f.statut='rejetee' THEN 3
                             WHEN f.statut='en_attente' THEN 2
                             WHEN f.statut='validee'    THEN 1 ELSE 0 END) AS _statut_global,
                    MAX(f.type_workflow) AS type_workflow,
                    MAX(CASE WHEN f.statut_chef    = 'rejete' THEN 2
                             WHEN f.statut_chef    = 'en_attente' THEN 1 ELSE 0 END) AS _sc,
                    MAX(CASE WHEN f.statut_dir_adj = 'rejete' THEN 2
                             WHEN f.statut_dir_adj = 'en_attente' THEN 1 ELSE 0 END) AS _sd,
                    MAX(CASE WHEN f.statut_dir     = 'rejete' THEN 2
                             WHEN f.statut_dir     = 'en_attente' THEN 1 ELSE 0 END) AS _sdr,
                    MAX(CASE WHEN f.statut_dei     = 'rejete' THEN 2
                             WHEN f.statut_dei     = 'en_attente' THEN 1 ELSE 0 END) AS _sdei,
                    MAX(CASE WHEN f.statut_vp_eip  = 'rejete' THEN 2
                             WHEN f.statut_vp_eip  = 'en_attente' THEN 1 ELSE 0 END) AS _svp
             FROM enseignants e
             JOIN fiches f ON f.enseignant_id = e.id
             WHERE 1=1 $sw $extraWhere
             GROUP BY e.id
             $havingClause
             ORDER BY e.etab_beneficiaire, e.departement, e.nom"
        );
        $stmt->execute(array_merge($sp, $extraParams));
        $rows = $stmt->fetchAll();
        // Convertir les codes agrégés en labels lisibles
        $conv  = function($v){ if($v==2) return 'rejete'; if($v==1) return 'en_attente'; return 'valide'; };
        $convG = function($v){ if($v==3) return 'rejetee'; if($v==2) return 'en_attente'; return 'validee'; };
        return array_map(function($r) use ($conv, $convG) {
            $r['st_chef']       = $conv((int)($r['_sc']   ?? 1));
            $r['st_dir_adj']    = $conv((int)($r['_sd']   ?? 1));
            $r['st_dir']        = $conv((int)($r['_sdr']  ?? 1));
            $r['st_dei']        = $conv((int)($r['_sdei'] ?? 1));
            $r['st_vp_eip']     = $conv((int)($r['_svp']  ?? 0));
            $r['statut_global'] = $convG((int)($r['_statut_global'] ?? 2));
            return $r;
        }, $rows);
    }

    // ── Toutes les fiches d'un enseignant pour le portail ─────
    public function getFichesEnseignantPortail(int $ensId): array
    {
        [$sw, $sp] = Auth::ficheScope();
        $stmt = $this->pdo->prepare(
            "SELECT f.*, e.nom AS ens_nom, e.matricule, e.departement,
                    e.etab_beneficiaire, e.etab_rattachement, e.etab_administratif,
                    e.grade, e.type_enseignant, e.id AS enseignant_id,
                    (SELECT COUNT(*) FROM preuves p WHERE p.fiche_id = f.id) AS nb_preuves
             FROM fiches f
             JOIN enseignants e ON e.id = f.enseignant_id
             WHERE f.enseignant_id = ? $sw
             ORDER BY f.semestre, f.submitted_at"
        );
        $stmt->execute(array_merge([$ensId], $sp));
        return $stmt->fetchAll();
    }

    // ── Preuves d'une fiche ──────────────────────────────────
    public function getPreuves(int $ficheId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM preuves WHERE fiche_id = ? ORDER BY uploaded_at DESC"
        );
        $stmt->execute([$ficheId]);
        return $stmt->fetchAll();
    }

    // ── Enregistrer une preuve ───────────────────────────────
    public function addPreuve(
        int $ficheId, string $nomOriginal, string $nomStockage, string $mime, int $taille,
        ?int $volCm = null, ?int $volTd = null, string $commentaire = ''
    ): int {
        $this->pdo->prepare(
            "INSERT INTO preuves (fiche_id, nom_original, nom_stockage, type_mime, taille,
             volume_cm_effectue, volume_td_effectue, commentaire)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        )->execute([$ficheId, $nomOriginal, $nomStockage, $mime, $taille, $volCm, $volTd, $commentaire]);
        return (int)$this->pdo->lastInsertId();
    }

    // ── Supprimer une preuve ─────────────────────────────────
    public function deletePreuve(int $preuveId): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM preuves WHERE id = ? LIMIT 1");
        $stmt->execute([$preuveId]);
        $preuve = $stmt->fetch();
        if ($preuve) {
            $this->pdo->prepare("DELETE FROM preuves WHERE id = ?")->execute([$preuveId]);
        }
        return $preuve ?: null;
    }

    // ── Preuve par ID ────────────────────────────────────────
    public function getPreuve(int $preuveId): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM preuves WHERE id = ? LIMIT 1");
        $stmt->execute([$preuveId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }
}
