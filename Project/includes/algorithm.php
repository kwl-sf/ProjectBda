<?php
require_once 'config.php';

/* ================== CONSTANTES ================== */
define('MAX_EXAMS_PROF_DAY', 3);
define('EXAM_DURATION', 90);
define('AMPHI_RATE', 0.25);

/* ================== FONCTION PRINCIPALE ================== */
function genererEmploiDuTemps($params, $pdo) {

    if (!is_array($params)) $params = [];

    try {
        $pdo->beginTransaction();

        $semestre   = $params['semestre']   ?? 'S1';
        $start_date = $params['start_date'] ?? date('Y-m-d');
        $end_date   = $params['end_date']   ?? date('Y-m-d', strtotime('+21 days'));
        $session    = $params['session']    ?? 'normale';

        /* ================== MODULES ================== */
        $modules = getModulesSemestre($semestre);
        if (empty($modules)) {
            throw new Exception("Aucun module trouvé");
        }

        /* ================== SALLES ================== */
        $salles = getSallesDisponibles();
        if (empty($salles)) {
            throw new Exception("Aucune salle disponible");
        }

        // priorité amphi puis capacité
        usort($salles, function ($a, $b) {
            if ($a['type'] === 'amphi' && $b['type'] !== 'amphi') return -1;
            if ($a['type'] !== 'amphi' && $b['type'] === 'amphi') return 1;
            return $b['capacite'] - $a['capacite'];
        });

        /* ================== CALENDRIER ================== */
        $calendar = creerCalendrier($start_date, $end_date);
        $jours = array_keys($calendar);
        $dayIndex = 0;

        $examens_crees = 0;

        /* ================== BOUCLE MODULES ================== */
        foreach ($modules as $module) {

            // créneau unique pour le module
            [$jour, $heure] = trouverCreneauLibre(
                $module['prof_id'],
                $calendar,
                $jours,
                $dayIndex
            );

            if (!$jour) continue;

            $etudiants = getEtudiantsModuleFormation(
                $module['formation_id'],
                $module['id'],
                $semestre
            );

            if (empty($etudiants)) continue;

            $index = 0;
            $total = count($etudiants);

            foreach ($salles as $salle) {

                if ($index >= $total) break;
                if (!salleDisponible($salle['id'], $jour, $heure)) continue;

                if ($salle['type'] === 'amphi') {
                    // لا تستعمل amphi إذا الباقي ≤ 50
                    if (($total - $index) <= 50) continue;
                    $capacite = max(1, floor($salle['capacite'] * AMPHI_RATE));
                } else {
                    $capacite = $salle['capacite'];
                }

                $lot = array_slice($etudiants, $index, $capacite);
                if (empty($lot)) continue;

                // إنشاء الامتحان
                $stmt = $pdo->prepare("
                    INSERT INTO examens
                    (module_id, prof_id, salle_id, date_heure, duree_minutes, session, statut)
                    VALUES (?, ?, ?, ?, ?, ?, 'planifie')
                ");
                $stmt->execute([
                    $module['id'],
                    $module['prof_id'],
                    $salle['id'],
                    "$jour $heure",
                    EXAM_DURATION,
                    $session
                ]);

                $examen_id = $pdo->lastInsertId();

                // ربط الطلبة
                $stmt2 = $pdo->prepare("
                    INSERT INTO examens_etudiants (examen_id, etudiant_id)
                    VALUES (?, ?)
                ");

                foreach ($lot as $et) {
                    $stmt2->execute([$examen_id, $et['id']]);
                }

                $index += count($lot);
                $examens_crees++;
            }
        }

        $pdo->commit();
        return [
            'success' => true,
            'exams_generated' => $examens_crees
        ];

    } catch (Exception $e) {
        $pdo->rollBack();
        return [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}

/* ================== CALENDRIER ================== */
function creerCalendrier($start, $end) {

    $calendar = [];
    $date = new DateTime($start);
    $end  = new DateTime($end);

    while ($date <= $end) {

        $day = $date->format('N'); // 1=lundi ... 5=vendredi

        // ❌ لا امتحانات يوم الجمعة
        if ($day != 5) {
            $calendar[$date->format('Y-m-d')] = [
                '08:00:00',
                '09:40:00',
                '11:20:00',
                '13:00:00'
            ];
        }

        $date->modify('+1 day');
    }

    return $calendar;
}

/* ================== DISPONIBILITÉS ================== */
function trouverCreneauLibre($prof_id, $calendar, $jours, &$d) {

    global $pdo;

    $total = count($jours);
    $tries = 0;

    while ($tries < $total * 4) {

        $jour = $jours[$d];

        foreach ($calendar[$jour] as $heure) {
            if (profDisponible($prof_id, $jour)) {
                $d = ($d + 1) % $total;
                return [$jour, $heure];
            }
        }

        $d = ($d + 1) % $total;
        $tries++;
    }

    return [null, null];
}

function profDisponible($prof_id, $jour) {
    global $pdo;

    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT module_id)
        FROM examens
        WHERE prof_id = ? AND DATE(date_heure) = ?
    ");
    $stmt->execute([$prof_id, $jour]);

    return $stmt->fetchColumn() < MAX_EXAMS_PROF_DAY;
}

function salleDisponible($salle_id, $jour, $heure) {
    global $pdo;

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM examens
        WHERE salle_id = ? AND date_heure = ?
    ");
    $stmt->execute([$salle_id, "$jour $heure"]);

    return $stmt->fetchColumn() == 0;
}

/* ================== DONNÉES ================== */
function getModulesSemestre($semestre) {
    global $pdo;

    $stmt = $pdo->prepare("
        SELECT DISTINCT m.*
        FROM modules m
        JOIN inscriptions i ON m.id = i.module_id
        WHERE i.semestre = ?
    ");
    $stmt->execute([$semestre]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getSallesDisponibles() {
    global $pdo;
    return $pdo->query("SELECT * FROM lieu_examen WHERE disponible = 1")
               ->fetchAll(PDO::FETCH_ASSOC);
}

function getEtudiantsModuleFormation($formation_id, $module_id, $semestre) {
    global $pdo;

    $stmt = $pdo->prepare("
        SELECT e.id, e.nom, e.prenom
        FROM etudiants e
        JOIN inscriptions i ON e.id = i.etudiant_id
        WHERE e.formation_id = ?
          AND i.module_id = ?
          AND i.semestre = ?
    ");
    $stmt->execute([$formation_id, $module_id, $semestre]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/* ================== CONFLITS ================== */
function detecterConflits() {

    global $pdo;

    $pdo->exec("DELETE FROM conflits WHERE statut = 'detecte'");
    $count = 0;

    // étudiants : >1 examen / jour
    $stmt = $pdo->query("
        SELECT ee.etudiant_id, DATE(e.date_heure) j, COUNT(*) nb
        FROM examens_etudiants ee
        JOIN examens e ON ee.examen_id = e.id
        GROUP BY ee.etudiant_id, j
        HAVING nb > 1
    ");

    while ($r = $stmt->fetch()) {
        insertConflit('etudiant', "Étudiant avec plusieurs examens le {$r['j']}");
        $count++;
    }

    return ['success' => true, 'count' => $count];
}

function insertConflit($type, $desc) {
    global $pdo;

    $stmt = $pdo->prepare("
        INSERT INTO conflits (type, description, statut)
        VALUES (?, ?, 'detecte')
    ");
    $stmt->execute([$type, $desc]);
}