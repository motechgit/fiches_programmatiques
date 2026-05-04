<?php
// ============================================================
// src/Auth.php — Authentification multi-rôles (portal admin)
// Rôles : dei | directeur | directeur_adjoint | chef_dept | vp_eip
//
// Workflows de validation :
//   IESR_UJKZ  : chef_dept(rattach.) → dir_adj(rattach.) → dir(rattach.) → DEI
//   IESR_HORS  : chef_dept(benef.)   → dir_adj(benef.)   → dir(benef.)   → DEI
//   VACATAIRE  : chef_dept(benef.)   → dir_adj(benef.)   → dir(benef.)   → DEI → VP_EIP
// ============================================================
declare(strict_types=1);

class Auth
{
    private static array $roleLabels = [
        'dei'               => 'DEI',
        'directeur'         => 'Directeur',
        'directeur_adjoint' => 'Directeur adjoint',
        'chef_dept'         => 'Chef de département',
        'vp_eip'            => 'VP EIP',
    ];

    // ── Connexion ────────────────────────────────────────────
    public static function login(string $login, string $password): ?array
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare(
            "SELECT * FROM utilisateurs WHERE login = ? AND actif = 1 LIMIT 1"
        );
        $stmt->execute([trim($login)]);
        $user = $stmt->fetch();

        if (!$user) {
            password_verify('dummy', '$argon2id$v=19$m=65536,t=4,p=1$dummy$dummy');
            return null;
        }
        if (!password_verify($password, $user['password'])) {
            return null;
        }
        if (password_needs_rehash($user['password'], PASSWORD_ARGON2ID)) {
            $pdo->prepare("UPDATE utilisateurs SET password=? WHERE id=?")
                ->execute([password_hash($password, PASSWORD_ARGON2ID), $user['id']]);
        }
        return $user;
    }

    // ── Session ──────────────────────────────────────────────
    public static function startSession(array $user): void
    {
        session_regenerate_id(true);
        $pdo2 = Database::getInstance(); // initialisé en premier pour tout le bloc
        $_SESSION['user_id']    = $user['id'];
        $_SESSION['user_login'] = $user['login'];
        $_SESSION['user_nom']   = $user['nom'];
        $_SESSION['user_role']  = $user['role'];
        $_SESSION['user_dept'] = $user['departement'];
        // Résoudre dept_id depuis le nom si departement_id est NULL en BD
        $deptId = (int)($user['departement_id'] ?? 0);
        if ($deptId === 0 && !empty($user['departement'])) {
            // Chercher l'ID depuis le nom "Nom (SIGLE)" ou juste "Nom"
            $stDept = $pdo2->prepare(
                "SELECT id FROM departements
                 WHERE CONCAT(nom, IF(sigle!='', CONCAT(' (',sigle,')'), '')) = ?
                    OR nom = ?
                 LIMIT 1"
            );
            $stDept->execute([$user['departement'], $user['departement']]);
            $dRow = $stDept->fetch();
            if ($dRow) {
                $deptId = (int)$dRow['id'];
                // Mettre à jour en BD pour les prochaines connexions
                $pdo2->prepare("UPDATE utilisateurs SET departement_id=? WHERE id=?")
                     ->execute([$deptId, $user['id']]);
            }
        }
        $_SESSION['user_dept_id'] = $deptId;
        // etablissement peut être un JSON array (directeur multi-étabs) ou une string
        $etabRaw = $user['etablissement'] ?? null;
        $etabDec = $etabRaw ? json_decode($etabRaw, true) : null;
        if (is_array($etabDec)) {
            $_SESSION['user_etabs'] = $etabDec;
            $_SESSION['user_etab']  = $etabDec[0] ?? '';
        } else {
            $_SESSION['user_etabs'] = $etabRaw ? [$etabRaw] : [];
            $_SESSION['user_etab']  = $etabRaw ?? null;
        }
        // Charger les IDs d'établissements depuis la DB
        if (!empty($_SESSION['user_etabs'])) {
            $etabIdsFound = [];
            foreach ($_SESSION['user_etabs'] as $etabNom) {
                // Chercher par nom exact OU par sigle (ex: "UFR/SVT — ..." ou "UFR/SVT")
                $stE2 = $pdo2->prepare(
                    "SELECT id FROM etablissements
                     WHERE nom = ? OR sigle = ? OR nom LIKE ?
                     LIMIT 1"
                );
                $sigleOnly = explode(' —', $etabNom)[0]; // "UFR/SVT" depuis "UFR/SVT — ..."
                $stE2->execute([$etabNom, $sigleOnly, $sigleOnly . '%']);
                $eRow = $stE2->fetch();
                if ($eRow) $etabIdsFound[] = (int)$eRow['id'];
            }
            $_SESSION['user_etab_ids'] = array_values(array_unique($etabIdsFound));
        } else {
            $_SESSION['user_etab_ids'] = [];
        }
        // Également inclure etablissement_id si disponible
        if (!empty($user['etablissement_id'])) {
            $eid = (int)$user['etablissement_id'];
            if (!in_array($eid, $_SESSION['user_etab_ids'], true)) {
                $_SESSION['user_etab_ids'][] = $eid;
            }
        }
        $_SESSION['user_since'] = time();
    }

    // ── Vérification session ─────────────────────────────────
    public static function check(int $timeoutSec = 28800): bool // 8h par défaut
    {
        if (empty($_SESSION['user_id']) || empty($_SESSION['user_role'])) {
            return false;
        }
        // Si user_since absent (ancienne session), le réinitialiser
        if (empty($_SESSION['user_since'])) {
            $_SESSION['user_since'] = time();
        }
        if (time() - $_SESSION['user_since'] > $timeoutSec) {
            self::logout();
            return false;
        }
        $_SESSION['user_since'] = time();
        return true;
    }

    // ── Déconnexion ──────────────────────────────────────────
    public static function logout(): void
    {
        unset($_SESSION['user_id'], $_SESSION['user_login'], $_SESSION['user_nom'],
              $_SESSION['user_role'], $_SESSION['user_dept'], $_SESSION['user_dept_id'],
              $_SESSION['user_etab'], $_SESSION['user_etabs'], $_SESSION['user_etab_ids'],
              $_SESSION['user_since']);
        session_regenerate_id(true);
    }

    // ── Getters session ──────────────────────────────────────
    public static function userId(): int     { return (int)($_SESSION['user_id'] ?? 0); }
    public static function userRole(): string { return $_SESSION['user_role'] ?? ''; }
    public static function userNom(): string  { return $_SESSION['user_nom']  ?? ''; }
    public static function userDept(): ?string { return $_SESSION['user_dept'] ?? null; }
    public static function userEtab(): ?string { return $_SESSION['user_etab'] ?? null; }
    public static function isDei(): bool       { return self::userRole() === 'dei'; }
    public static function isVpEip(): bool     { return self::userRole() === 'vp_eip'; }
    // Retourne la liste complète des établissements de l'utilisateur
    public static function userEtabs(): array  { return $_SESSION['user_etabs'] ?? (self::userEtab() ? [self::userEtab()] : []); }
    // Retourne l'ID du département de l'utilisateur (stocké en session)
    public static function userDeptId(): int   { return (int)($_SESSION['user_dept_id']  ?? 0); }
    // Retourne les IDs des établissements de l'utilisateur
    public static function userEtabIds(): array { return $_SESSION['user_etab_ids'] ?? []; }

    // ── Déterminer le type de workflow d'une fiche ───────────
    // - VACATAIRE  : type_enseignant = 'vacataire'
    // - IESR_UJKZ  : IESR dont etab_rattachement (université) est l'UJKZ
    // - IESR_HORS  : IESR d'une autre université
    public static function typeWorkflow(array $enseignant): string
    {
        $type = $enseignant['type_enseignant'] ?? 'permanent';
        if ($type === 'vacataire') {
            return 'VACATAIRE';
        }
        // Détecter UJKZ depuis l'IESR de rattachement (université nationale)
        $rattach = strtolower(trim($enseignant['etab_rattachement'] ?? ''));
        $ujkz = $rattach === ''
             || strpos($rattach, 'ujkz') !== false
             || strpos($rattach, 'ki-zerbo') !== false
             || strpos($rattach, 'ki zerbo') !== false;
        return $ujkz ? 'IESR_UJKZ' : 'IESR_HORS';
    }

    // ── Scope : quelles fiches cet utilisateur peut-il voir ? ─
    // Retourne [clause WHERE additionnelle, params]
    public static function ficheScope(): array
    {
        $role = self::userRole();

        // DEI et VP EIP : accès global à toutes les fiches
        if ($role === 'dei' || $role === 'vp_eip') {
            return ['', []];
        }

        if ($role === 'chef_dept') {
            $dept = self::userDept();
            // Si dept_id pas encore en session, le résoudre maintenant sans attendre reconnexion
            $deptId = self::userDeptId();
            if ($deptId === 0 && !empty($dept)) {
                $pdo2 = Database::getInstance();
                $stD  = $pdo2->prepare(
                    "SELECT id FROM departements
                     WHERE CONCAT(nom, IF(sigle!='', CONCAT(' (',sigle,')'), '')) = ?
                        OR nom = ? LIMIT 1"
                );
                $stD->execute([$dept, $dept]);
                $row = $stD->fetch();
                if ($row) {
                    $deptId = (int)$row['id'];
                    $_SESSION['user_dept_id'] = $deptId;
                    // Mettre à jour en BD
                    $pdo2->prepare("UPDATE utilisateurs SET departement_id=? WHERE id=?")
                         ->execute([$deptId, self::userId()]);
                }
            }
            if ($deptId > 0) {
                // Comparaison par ID (robuste)
                return [
                    "AND (
                        (f.type_workflow = 'IESR_UJKZ' AND e.departement = ?)
                        OR
                        (f.type_workflow != 'IESR_UJKZ' AND f.dept_beneficiaire_fiche = ?)
                    )",
                    [$dept, $deptId]
                ];
            }
            // Fallback : dept_id non résolu → montrer IESR_UJKZ par nom seulement
            // (les fiches HORS/VACATAIRE ne seront visibles qu'après reconnexion)
            return [
                "AND (f.type_workflow = 'IESR_UJKZ' AND e.departement = ?)",
                [$dept]
            ];
        }

        // directeur / directeur_adjoint : filtrent par liste d'établissements (JSON multi-étabs)
        // IESR_UJKZ  : par établissement de rattachement administratif (etab_administratif)
        // IESR_HORS  : par établissement bénéficiaire de la fiche (etab_beneficiaire_fiche)
        // VACATAIRE  : par établissement bénéficiaire de la fiche (etab_beneficiaire_fiche)
        if ($role === 'directeur_adjoint' || $role === 'directeur') {
            $etabs = self::userEtabs();
            if (empty($etabs)) {
                return ['AND 1=0', []]; // aucun étab configuré = rien visible
            }
            $ph = implode(',', array_fill(0, count($etabs), '?'));
            // Résoudre les etab_ids si pas encore en session
            $etabIds = self::userEtabIds();
            if (empty($etabIds) && !empty($etabs)) {
                $pdo2 = Database::getInstance();
                $etabIds = [];
                foreach ($etabs as $etabNom) {
                    $stE = $pdo2->prepare(
                        "SELECT id FROM etablissements WHERE nom=? OR sigle=? OR nom LIKE ? LIMIT 1"
                    );
                    $sigleOnly = explode(' —', $etabNom)[0];
                    $stE->execute([$etabNom, $sigleOnly, $sigleOnly.'%']);
                    $eRow = $stE->fetch();
                    if ($eRow) $etabIds[] = (int)$eRow['id'];
                }
                if (!empty($etabIds)) {
                    $_SESSION['user_etab_ids'] = $etabIds;
                }
            }
            if (!empty($etabIds)) {
                $phId = implode(',', array_fill(0, count($etabIds), '?'));
                return [
                    "AND (
                        (f.type_workflow = 'IESR_UJKZ' AND e.etab_administratif IN ($ph))
                        OR
                        (f.type_workflow != 'IESR_UJKZ' AND f.etab_beneficiaire_fiche IN ($phId))
                    )",
                    array_merge($etabs, $etabIds)
                ];
            }
            return [
                "AND (
                    (f.type_workflow = 'IESR_UJKZ' AND e.etab_administratif IN ($ph))
                    OR
                    (f.type_workflow != 'IESR_UJKZ' AND f.etab_beneficiaire_fiche = 0)
                )",
                $etabs
            ];
        }
        return ['', []];
    }

    // ── Peut-il valider cette fiche à cette étape ? ──────────
    //
    // Workflows :
    //   IESR_UJKZ  : chef(rattach.) → dir_adj(rattach.) → dir(rattach.) → DEI
    //   IESR_HORS  : chef(benef.)   → dir_adj(benef.)   → dir(benef.)   → DEI
    //   VACATAIRE  : chef(benef.)   → dir_adj(benef.)   → dir(benef.)   → DEI → VP_EIP
    //
    // Pour IESR_HORS et VACATAIRE, le validateur doit appartenir
    // à l'établissement/département BÉNÉFICIAIRE de la fiche.
    //
    public static function peutValider(string $role, array $fiche): bool
    {
        $wf = $fiche['type_workflow'] ?? 'IESR_UJKZ';

        if ($role === 'chef_dept') {
            if ($fiche['statut_chef'] !== 'en_attente') return false;
            // Pour IESR_HORS et VACATAIRE : vérifier que le chef est du département bénéficiaire
            if ($wf !== 'IESR_UJKZ') {
                $deptId = self::userDeptId();
                $ficheDeptId = (int)($fiche['dept_beneficiaire_fiche'] ?? 0);
                if ($deptId > 0 && $ficheDeptId > 0 && $deptId !== $ficheDeptId) return false;
            }
            return true;
        }

        if ($role === 'directeur_adjoint') {
            if ($fiche['statut_chef'] !== 'valide') return false;
            if ($fiche['statut_dir_adj'] !== 'en_attente') return false;
            // Pour IESR_HORS et VACATAIRE : vérifier que le dir_adj est de l'étab bénéficiaire
            if ($wf !== 'IESR_UJKZ') {
                $ficheEtabId = (int)($fiche['etab_beneficiaire_fiche'] ?? 0);
                $userEtabIds = self::userEtabIds();
                if ($ficheEtabId > 0 && !empty($userEtabIds) && !in_array($ficheEtabId, $userEtabIds, true)) return false;
            }
            return true;
        }

        if ($role === 'directeur') {
            if ($fiche['statut_dir_adj'] !== 'valide') return false;
            if ($fiche['statut_dir'] !== 'en_attente') return false;
            // Pour IESR_HORS et VACATAIRE : vérifier que le directeur est de l'étab bénéficiaire
            if ($wf !== 'IESR_UJKZ') {
                $ficheEtabId = (int)($fiche['etab_beneficiaire_fiche'] ?? 0);
                $userEtabIds = self::userEtabIds();
                if ($ficheEtabId > 0 && !empty($userEtabIds) && !in_array($ficheEtabId, $userEtabIds, true)) return false;
            }
            return true;
        }

        if ($role === 'dei') {
            return $fiche['statut_dir'] === 'valide'
                && $fiche['statut_dei'] === 'en_attente';
        }

        if ($role === 'vp_eip') {
            // VP EIP uniquement pour les vacataires, après validation DEI
            return $wf === 'VACATAIRE'
                && $fiche['statut_dei'] === 'valide'
                && ($fiche['statut_vp_eip'] ?? 'non_requis') === 'en_attente';
        }

        return false;
    }

    // ── Étapes du workflow selon le type ─────────────────────
    // Retourne la liste ordonnée des rôles valideurs
    public static function etapesWorkflow(string $typeWorkflow): array
    {
        if ($typeWorkflow === 'VACATAIRE') {
            return ['chef_dept', 'directeur_adjoint', 'directeur', 'dei', 'vp_eip'];
        }
        // IESR_UJKZ et IESR_HORS : même circuit, DEI en dernier
        return ['chef_dept', 'directeur_adjoint', 'directeur', 'dei'];
    }

    // ── Heures sup affichées ? (pas pour IESR_HORS et VACATAIRE) ─
    public static function afficherHeuresSup(string $typeWorkflow): bool
    {
        return $typeWorkflow === 'IESR_UJKZ';
    }

    // ── Encadrement autorisé ? (uniquement IESR_UJKZ) ────────
    public static function encadrementAutorise(string $typeWorkflow): bool
    {
        return $typeWorkflow === 'IESR_UJKZ';
    }

    // ── Label du rôle ────────────────────────────────────────
    public static function roleLabel(string $role): string
    {
        return self::$roleLabels[$role] ?? $role;
    }

    // ── Créer un utilisateur ─────────────────────────────────
    public static function createUser(array $data): int
    {
        $pdo = Database::getInstance();
        $hash = password_hash($data['password'], PASSWORD_ARGON2ID, [
            'memory_cost' => 65536, 'time_cost' => 4, 'threads' => 1,
        ]);
        $pdo->prepare(
            "INSERT INTO utilisateurs (nom, login, password, role, departement, etablissement)
             VALUES (?, ?, ?, ?, ?, ?)"
        )->execute([
            $data['nom'], $data['login'], $hash, $data['role'],
            $data['departement'] ?? null, $data['etablissement'] ?? null,
        ]);
        return (int)$pdo->lastInsertId();
    }

    // ── Changer le mot de passe ──────────────────────────────
    public static function changePassword(int $userId, string $newPassword): void
    {
        $hash = password_hash($newPassword, PASSWORD_ARGON2ID, [
            'memory_cost' => 65536, 'time_cost' => 4, 'threads' => 1,
        ]);
        Database::getInstance()->prepare(
            "UPDATE utilisateurs SET password=? WHERE id=?"
        )->execute([$hash, $userId]);
    }

    // ── Lister tous les utilisateurs ────────────────────────
    public static function listUsers(): array
    {
        return Database::getInstance()->query(
            "SELECT id, nom, login, role, departement, etablissement, actif, created_at
             FROM utilisateurs ORDER BY role, nom"
        )->fetchAll();
    }
}
