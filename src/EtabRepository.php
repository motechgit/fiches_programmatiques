<?php
// ============================================================
// src/EtabRepository.php — CRUD Établissements & Départements
// Accessible : DEI et admin uniquement
// ============================================================
declare(strict_types=1);

class EtabRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::getInstance();
    }

    // ══════════════════════════════════════════════════════════
    // ÉTABLISSEMENTS
    // ══════════════════════════════════════════════════════════

    public function getAllEtabs(bool $actifSeulement = false): array
    {
        $sql = "SELECT e.*,
                    (SELECT COUNT(*) FROM departements d WHERE d.etablissement_id = e.id AND d.actif = 1) AS nb_departements,
                    (SELECT COUNT(*) FROM utilisateurs u WHERE u.etablissement_id = e.id AND u.actif = 1) AS nb_utilisateurs
                FROM etablissements e";
        if ($actifSeulement) $sql .= " WHERE e.actif = 1";
        $sql .= " ORDER BY e.ordre, e.sigle";
        return $this->pdo->query($sql)->fetchAll();
    }

    public function getEtabById(int $id): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM etablissements WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function createEtab(string $sigle, string $nom, int $ordre = 0): int
    {
        $this->pdo->prepare(
            "INSERT INTO etablissements (sigle, nom, ordre) VALUES (?, ?, ?)"
        )->execute([trim($sigle), trim($nom), $ordre]);
        return (int)$this->pdo->lastInsertId();
    }

    public function updateEtab(int $id, string $sigle, string $nom, int $ordre, bool $actif): bool
    {
        $stmt = $this->pdo->prepare(
            "UPDATE etablissements SET sigle=?, nom=?, ordre=?, actif=? WHERE id=?"
        );
        $stmt->execute([trim($sigle), trim($nom), $ordre, $actif ? 1 : 0, $id]);
        return $stmt->rowCount() > 0;
    }

    public function deleteEtab(int $id): bool
    {
        // Vérifier s'il y a des départements liés
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM departements WHERE etablissement_id = ?");
        $stmt->execute([$id]);
        if ((int)$stmt->fetchColumn() > 0) return false; // bloquer la suppression
        $stmt2 = $this->pdo->prepare("DELETE FROM etablissements WHERE id = ?");
        $stmt2->execute([$id]);
        return $stmt2->rowCount() > 0;
    }

    // ── Récupérer les utilisateurs liés à un établissement ───
    public function getUtilisateursByEtab(int $etabId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT id, nom, login, role, actif
             FROM utilisateurs
             WHERE etablissement_id = ?
               AND role IN ('directeur','directeur_adjoint')
             ORDER BY role, nom"
        );
        $stmt->execute([$etabId]);
        return $stmt->fetchAll();
    }

    // ── Lier un utilisateur à un établissement ───────────────
    public function lierUtilisateurEtab(int $userId, int $etabId): void
    {
        // Mettre à jour aussi la colonne texte pour compatibilité
        $etab = $this->getEtabById($etabId);
        $nomEtab = $etab ? $etab['nom'] : '';
        // Récupérer les étabs actuels (JSON) et ajouter celui-ci
        $stmt = $this->pdo->prepare("SELECT etablissement FROM utilisateurs WHERE id = ?");
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        $etabsActuels = [];
        if ($row && !empty($row['etablissement'])) {
            $dec = json_decode($row['etablissement'], true);
            $etabsActuels = is_array($dec) ? $dec : [$row['etablissement']];
        }
        if (!in_array($nomEtab, $etabsActuels, true)) {
            $etabsActuels[] = $nomEtab;
        }
        $this->pdo->prepare(
            "UPDATE utilisateurs SET etablissement_id = ?, etablissement = ? WHERE id = ?"
        )->execute([$etabId, json_encode($etabsActuels, JSON_UNESCAPED_UNICODE), $userId]);
    }

    public function delierUtilisateurEtab(int $userId, int $etabId): void
    {
        $etab = $this->getEtabById($etabId);
        $nomEtab = $etab ? $etab['nom'] : '';
        $stmt = $this->pdo->prepare("SELECT etablissement FROM utilisateurs WHERE id = ?");
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        $etabsActuels = [];
        if ($row && !empty($row['etablissement'])) {
            $dec = json_decode($row['etablissement'], true);
            $etabsActuels = is_array($dec) ? $dec : [$row['etablissement']];
        }
        $etabsActuels = array_values(array_filter($etabsActuels, function($e) use ($nomEtab) {
            return $e !== $nomEtab;
        }));
        $newJson = !empty($etabsActuels) ? json_encode($etabsActuels, JSON_UNESCAPED_UNICODE) : null;
        $newId   = !empty($etabsActuels) ? null : null; // remettre à null si plus aucun étab
        $this->pdo->prepare(
            "UPDATE utilisateurs SET etablissement_id = ?, etablissement = ? WHERE id = ?"
        )->execute([$newId, $newJson, $userId]);
    }

    // ══════════════════════════════════════════════════════════
    // DÉPARTEMENTS
    // ══════════════════════════════════════════════════════════

    public function getAllDepts(bool $actifSeulement = false, ?int $etabId = null): array
    {
        $where = [];
        $params = [];
        if ($actifSeulement) { $where[] = 'd.actif = 1'; }
        if ($etabId !== null) { $where[] = 'd.etablissement_id = ?'; $params[] = $etabId; }
        $sql = "SELECT d.*, e.nom AS etab_nom, e.sigle AS etab_sigle,
                    (SELECT COUNT(*) FROM utilisateurs u
                     WHERE u.departement_id = d.id AND u.actif = 1) AS nb_utilisateurs
                FROM departements d
                JOIN etablissements e ON e.id = d.etablissement_id"
             . (!empty($where) ? ' WHERE ' . implode(' AND ', $where) : '')
             . " ORDER BY e.ordre, e.sigle, d.ordre, d.nom";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function getDeptById(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT d.*, e.nom AS etab_nom, e.sigle AS etab_sigle
             FROM departements d
             JOIN etablissements e ON e.id = d.etablissement_id
             WHERE d.id = ?"
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function getDeptsByEtab(int $etabId, bool $actifSeulement = true): array
    {
        $sql = "SELECT * FROM departements WHERE etablissement_id = ?"
             . ($actifSeulement ? " AND actif = 1" : "")
             . " ORDER BY ordre, nom";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$etabId]);
        return $stmt->fetchAll();
    }

    public function createDept(int $etabId, string $nom, string $sigle = '', int $ordre = 0): int
    {
        $this->pdo->prepare(
            "INSERT INTO departements (etablissement_id, nom, sigle, ordre) VALUES (?, ?, ?, ?)"
        )->execute([$etabId, trim($nom), trim($sigle), $ordre]);
        return (int)$this->pdo->lastInsertId();
    }

    public function updateDept(int $id, int $etabId, string $nom, string $sigle, int $ordre, bool $actif): bool
    {
        $stmt = $this->pdo->prepare(
            "UPDATE departements SET etablissement_id=?, nom=?, sigle=?, ordre=?, actif=? WHERE id=?"
        );
        $stmt->execute([$etabId, trim($nom), trim($sigle), $ordre, $actif ? 1 : 0, $id]);
        return $stmt->rowCount() > 0;
    }

    public function deleteDept(int $id): bool
    {
        // Vérifier qu'aucun utilisateur n'est lié
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM utilisateurs WHERE departement_id = ?");
        $stmt->execute([$id]);
        if ((int)$stmt->fetchColumn() > 0) return false;
        $stmt2 = $this->pdo->prepare("DELETE FROM departements WHERE id = ?");
        $stmt2->execute([$id]);
        return $stmt2->rowCount() > 0;
    }

    // ── Récupérer le chef de département lié ─────────────────
    public function getChefDept(int $deptId): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT id, nom, login, actif
             FROM utilisateurs
             WHERE departement_id = ? AND role = 'chef_dept' AND actif = 1
             LIMIT 1"
        );
        $stmt->execute([$deptId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    // ── Lier un utilisateur à un département ─────────────────
    public function lierUtilisateurDept(int $userId, int $deptId): void
    {
        $dept = $this->getDeptById($deptId);
        $nomDept = $dept ? $dept['nom'] : '';
        $this->pdo->prepare(
            "UPDATE utilisateurs SET departement_id = ?, departement = ? WHERE id = ?"
        )->execute([$deptId, $nomDept, $userId]);
    }

    // ── Toutes les données pour l'arbre étab→dept→utilisateurs ─
    public function getArbreComplet(): array
    {
        $etabs = $this->getAllEtabs();
        foreach ($etabs as &$etab) {
            $etab['departements'] = $this->getDeptsByEtab((int)$etab['id'], false);
            foreach ($etab['departements'] as &$dept) {
                $dept['chef'] = $this->getChefDept((int)$dept['id']);
            }
            unset($dept);
            $etab['directeurs'] = $this->getUtilisateursByEtab((int)$etab['id']);
        }
        unset($etab);
        return $etabs;
    }

    // ── Construire le tableau [etab_nom => [dept_nom...]] ─────
    // Pour alimenter config/security.php dynamiquement
    public function getEtabDeptMap(): array
    {
        $depts = $this->getAllDepts(true);
        $map = [];
        foreach ($depts as $d) {
            $map[$d['etab_nom']][] = $d['nom'] . (!empty($d['sigle']) ? ' (' . $d['sigle'] . ')' : '');
        }
        return $map;
    }

    // ── Listes plates pour les selects/datalists ─────────────
    public function getListeEtabs(bool $actifSeulement = true): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT id, sigle, nom FROM etablissements"
            . ($actifSeulement ? " WHERE actif = 1" : "")
            . " ORDER BY ordre, sigle"
        );
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function getListeDepts(bool $actifSeulement = true, ?int $etabId = null): array
    {
        $where = $actifSeulement ? "WHERE d.actif = 1" : "WHERE 1=1";
        $params = [];
        if ($etabId !== null) { $where .= " AND d.etablissement_id = ?"; $params[] = $etabId; }
        $stmt = $this->pdo->prepare(
            "SELECT d.id, d.nom, d.sigle, d.etablissement_id, e.sigle AS etab_sigle
             FROM departements d JOIN etablissements e ON e.id = d.etablissement_id
             $where ORDER BY e.ordre, d.ordre, d.nom"
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
}
