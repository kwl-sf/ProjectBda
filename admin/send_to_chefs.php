<?php
// admin/send_to_chefs.php
require_once '../includes/config.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

// Vérifier que l'utilisateur est admin
require_role(['admin']);

$user = get_logged_in_user();

// Paramètres
$semestre = $_POST['semestre'] ?? '';
$session = $_POST['session'] ?? '';
$period = $_POST['period'] ?? '';
$message = $_POST['message'] ?? '';
$start_date = '';
$end_date = '';

// Parser la période
if (strpos($period, '_') !== false) {
    list($start_date, $end_date) = explode('_', $period);
}

try {
    // 1. الحصول على جميع الامتحانات المخططة للفصل الدراسي والفترة
    $stmt = $pdo->prepare("
        SELECT e.*, m.nom as module_nom, m.formation_id, 
               f.nom as formation_nom, f.dept_id,
               d.nom as departement_nom,
               p.nom as prof_nom, p.prenom as prof_prenom,
               l.nom as salle_nom, l.type as salle_type, l.capacite,
               GROUP_CONCAT(DISTINCT et.num_groupe ORDER BY et.num_groupe) as groupes
        FROM examens e
        JOIN modules m ON e.module_id = m.id
        JOIN formations f ON m.formation_id = f.id
        JOIN departements d ON f.dept_id = d.id
        JOIN professeurs p ON e.prof_id = p.id
        JOIN lieu_examen l ON e.salle_id = l.id
        LEFT JOIN examens_etudiants ee ON e.id = ee.examen_id
        LEFT JOIN etudiants et ON ee.etudiant_id = et.id
        WHERE e.statut = 'planifie'
        AND DATE(e.date_heure) BETWEEN ? AND ?
        GROUP BY e.id, m.nom, f.nom, d.nom, p.nom, p.prenom, l.nom, l.type, l.capacite
        ORDER BY d.id, e.date_heure
    ");
    $stmt->execute([$start_date, $end_date]);
    $examens = $stmt->fetchAll();
    
    if (empty($examens)) {
        throw new Exception("Aucun examen trouvé pour la période spécifiée");
    }
    
    // 2. تجميع الامتحانات حسب القسم
    $examens_par_departement = [];
    foreach ($examens as $examen) {
        $dept_id = $examen['dept_id'];
        if (!isset($examens_par_departement[$dept_id])) {
            $examens_par_departement[$dept_id] = [
                'departement_nom' => $examen['departement_nom'],
                'examens' => [],
                'formations' => []
            ];
        }
        $examens_par_departement[$dept_id]['examens'][] = $examen;
        
        // تجميع حسب التكوين داخل القسم
        $formation_id = $examen['formation_id'];
        if (!isset($examens_par_departement[$dept_id]['formations'][$formation_id])) {
            $examens_par_departement[$dept_id]['formations'][$formation_id] = [
                'nom' => $examen['formation_nom'],
                'examens' => []
            ];
        }
        $examens_par_departement[$dept_id]['formations'][$formation_id]['examens'][] = $examen;
    }
    
    // 3. البحث عن رؤساء الأقسام
    $departements_envoyes = 0;
    
    foreach ($examens_par_departement as $dept_id => $data_dept) {
        // البحث عن رئيس القسم
        $stmt = $pdo->prepare("SELECT * FROM professeurs WHERE dept_id = ? AND role = 'chef_dept'");
        $stmt->execute([$dept_id]);
        $chef_dept = $stmt->fetch();
        
        if ($chef_dept) {
            // 4. توليد HTML للواجهة كما تظهر في صفحة الإدمن
            $html_content = generer_html_emploi_du_temps($data_dept, $semestre, $session, $start_date, $end_date, $message);
            
            // 5. حفظ HTML في قاعدة البيانات (بدون تغيير حالة الامتحانات)
            $stmt = $pdo->prepare("
                INSERT INTO envois_chefs_departement 
                (admin_id, chef_dept_id, departement_id, semestre, session, 
                 date_debut, date_fin, nb_examens, message, statut, html_content)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'envoye', ?)
            ");
            $stmt->execute([
                $user['id'],
                $chef_dept['id'],
                $dept_id,
                $semestre,
                $session,
                $start_date,
                $end_date,
                count($data_dept['examens']),
                $message,
                $html_content
            ]);
            
            $envoi_id = $pdo->lastInsertId();
            
            // 6. إنشاء إشعار لرئيس القسم
            $stmt = $pdo->prepare("
                INSERT INTO notifications 
                (destinataire_id, destinataire_type, type, titre, contenu, lien, lue, created_at)
                VALUES (?, 'prof', 'emploi_temps', ?, ?, ?, 0, NOW())
            ");
            
            $titre = "Nouvel emploi du temps à valider";
            $contenu = "Un nouvel emploi du temps a été envoyé par l'administration pour votre département.";
            if (!empty($message)) {
                $contenu .= "\nMessage : " . $message;
            }
            
            $lien = "../chef_dept/validation.php?envoi_id=" . $envoi_id;
            
            $stmt->execute([
                $chef_dept['id'],
                $titre,
                $contenu,
                $lien
            ]);
            
            $departements_envoyes++;
        }
    }
    
    // 7. تسجيل النشاط
    $stmt = $pdo->prepare("
        INSERT INTO logs_activite (utilisateur_id, utilisateur_type, action, details) 
        VALUES (?, ?, ?, ?)
    ");
    $details = "Semestre: {$semestre}, Session: {$session}, Période: {$start_date} à {$end_date}";
    $details .= ", Départements: {$departements_envoyes}";
    $stmt->execute([$user['id'], 'admin', 'Envoi EDT aux chefs de département', $details]);
    
    // 8. رسالة النجاح
    $_SESSION['flash_message'] = "Emplois du temps envoyés avec succès à {$departements_envoyes} départements !";
    $_SESSION['flash_type'] = 'success';
    
    header("Location: generate_schedule.php");
    exit();
    
} catch (Exception $e) {
    $_SESSION['flash_message'] = "Erreur lors de l'envoi : " . $e->getMessage();
    $_SESSION['flash_type'] = 'error';
    header("Location: generate_schedule.php");
    exit();
}

/**
 * توليد HTML للواجهة كما تظهر في صفحة الإدمن
 */
function generer_html_emploi_du_temps($data_dept, $semestre, $session, $start_date, $end_date, $message) {
    $html = '<div class="emploi-du-temps-html" style="font-family: \'Poppins\', Arial, sans-serif;">';
    
    // En-tête مثل صفحة الإدمن
    $html .= '<div style="background: linear-gradient(135deg, #4361ee 0%, #3a0ca3 100%); border-radius: 10px; padding: 2rem; margin-bottom: 2rem; color: white; position: relative; overflow: hidden;">';
    $html .= '<h1 style="font-size: 2rem; margin-bottom: 0.5rem; position: relative; z-index: 1;">Emploi du Temps - ' . htmlspecialchars($data_dept['departement_nom']) . '</h1>';
    $html .= '<p style="opacity: 0.9; font-size: 1.1rem; position: relative; z-index: 1;">Semestre ' . htmlspecialchars($semestre) . ' - ' . htmlspecialchars($session) . '</p>';
    $html .= '<div style="display: inline-flex; align-items: center; gap: 0.5rem; background: rgba(255,255,255,0.2); padding: 0.5rem 1rem; border-radius: 50px; margin-top: 1rem; position: relative; z-index: 1; backdrop-filter: blur(10px);">';
    $html .= '<i class="fas fa-calendar-alt"></i>';
    $html .= '<span>Période: ' . date('d/m/Y', strtotime($start_date)) . ' au ' . date('d/m/Y', strtotime($end_date)) . '</span>';
    $html .= '</div>';
    
    if ($message) {
        $html .= '<div style="margin-top: 1rem; padding: 1rem; background: rgba(255,255,255,0.1); border-radius: 5px; position: relative; z-index: 1;">';
        $html .= '<strong><i class="fas fa-comment"></i> Message:</strong> ' . htmlspecialchars($message);
        $html .= '</div>';
    }
    $html .= '</div>';
    
    // عرض التكوينات كبطاقات مثل صفحة الإدمن
    foreach ($data_dept['formations'] as $formation_id => $formation) {
        $html .= '<div style="background: white; border-radius: 10px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); margin-bottom: 2rem; overflow: hidden;">';
        
        // En-tête de la formation
        $html .= '<div style="background: linear-gradient(135deg, #4361ee 0%, #3a0ca3 100%); color: white; padding: 1.5rem;">';
        $html .= '<h2 style="font-size: 1.5rem; font-weight: 700; margin-bottom: 0.5rem; display: flex; align-items: center; gap: 0.75rem;">';
        $html .= '<i class="fas fa-university"></i>';
        $html .= htmlspecialchars($formation['nom']);
        $html .= '</h2>';
        
        // Informations de la formation
        $html .= '<div style="display: flex; gap: 1.5rem; flex-wrap: wrap; margin-top: 1rem; font-size: 0.9rem; opacity: 0.9;">';
        $html .= '<div style="display: flex; align-items: center; gap: 0.5rem;">';
        $html .= '<i class="fas fa-building"></i>';
        $html .= htmlspecialchars($data_dept['departement_nom']);
        $html .= '</div>';
        $html .= '<div style="display: flex; align-items: center; gap: 0.5rem;">';
        $html .= '<i class="fas fa-book"></i>';
        $html .= count(array_unique(array_column($formation['examens'], 'module_nom'))) . ' modules';
        $html .= '</div>';
        $html .= '<div style="display: flex; align-items: center; gap: 0.5rem;">';
        $html .= '<i class="fas fa-calendar-check"></i>';
        $html .= count($formation['examens']) . ' examens planifiés';
        $html .= '</div>';
        $html .= '</div>';
        $html .= '</div>';
        
        // Tableau des examens - نفس تنسيق الإدمن
        $html .= '<table style="width: 100%; border-collapse: collapse;">';
        $html .= '<thead>';
        $html .= '<tr style="background: #f5f5f5;">';
        $html .= '<th style="padding: 1rem; text-align: left; font-weight: 600; color: #666; border-bottom: 2px solid #ddd; width: 40%;">Matière</th>';
        $html .= '<th style="padding: 1rem; text-align: left; font-weight: 600; color: #666; border-bottom: 2px solid #ddd; width: 60%;">Examens Planifiés</th>';
        $html .= '</tr>';
        $html .= '</thead>';
        $html .= '<tbody>';
        
        // تجميع الامتحانات حسب المادة
        $examens_par_module = [];
        foreach ($formation['examens'] as $examen) {
            $module_nom = $examen['module_nom'];
            if (!isset($examens_par_module[$module_nom])) {
                $examens_par_module[$module_nom] = [];
            }
            $examens_par_module[$module_nom][] = $examen;
        }
        
        foreach ($examens_par_module as $module_nom => $examens_module) {
            $first_examen = $examens_module[0];
            
            $html .= '<tr style="border-bottom: 1px solid #eee;">';
            $html .= '<td style="padding: 1rem; vertical-align: top;">';
            $html .= '<div style="margin-bottom: 1rem; padding-bottom: 1rem; border-bottom: 2px solid #eee;">';
            $html .= '<h3 style="font-size: 1.25rem; font-weight: 700; color: #333; margin-bottom: 0.5rem; line-height: 1.4;">' . htmlspecialchars($module_nom) . '</h3>';
            $html .= '<div style="display: flex; gap: 1rem; align-items: center; flex-wrap: wrap; color: #666; font-size: 0.95rem; margin-bottom: 0.25rem;">';
            $html .= '<span style="display: flex; align-items: center; gap: 0.5rem;">';
            $html .= '<i class="fas fa-hashtag"></i> ' . htmlspecialchars($semestre);
            $html .= '</span>';
            $html .= '<span style="margin: 0 0.5rem;">•</span>';
            $html .= '<span style="display: flex; align-items: center; gap: 0.5rem;">';
            $html .= '<i class="fas fa-clock"></i>';
            $duree_heures = floor($first_examen['duree_minutes'] / 60);
            $duree_minutes = $first_examen['duree_minutes'] % 60;
            $html .= $duree_heures . 'h' . ($duree_minutes < 10 ? '0' : '') . $duree_minutes;
            $html .= '</span>';
            $html .= '</div>';
            $html .= '</div>';
            $html .= '</td>';
            
            $html .= '<td style="padding: 1rem; vertical-align: top;">';
            
            // تجميع الامتحانات حسب التاريخ والوقت
            $examens_par_date = [];
            foreach ($examens_module as $examen) {
                $date_key = $examen['date_heure'];
                if (!isset($examens_par_date[$date_key])) {
                    $examens_par_date[$date_key] = [
                        'examen' => $examen,
                        'salles' => []
                    ];
                }
                $examens_par_date[$date_key]['salles'][] = $examen;
            }
            
            foreach ($examens_par_date as $date_heure => $data_examen) {
                $examen = $data_examen['examen'];
                
                $html .= '<div style="margin-bottom: 1.5rem;">';
                
                // En-tête المادة + التاريخ
                $html .= '<div style="margin-bottom: 1rem; padding-bottom: 1rem; border-bottom: 2px solid #eee;">';
                $html .= '<div style="font-size: 1.25rem; font-weight: 700; color: #333; margin-bottom: 0.5rem; line-height: 1.4;">';
                $html .= htmlspecialchars($module_nom) . ' – ' . htmlspecialchars($semestre) . ' – ';
                $html .= format_date_fr($date_heure, false);
                $html .= ' à ' . date('H:i', strtotime($date_heure));
                $html .= '</div>';
                $html .= '<div style="display: flex; gap: 1rem; align-items: center; flex-wrap: wrap; color: #666; font-size: 0.95rem;">';
                $html .= '<span style="display: flex; align-items: center; gap: 0.5rem;">';
                $html .= '<i class="fas fa-clock"></i>';
                $duree_heures = floor($examen['duree_minutes'] / 60);
                $duree_minutes = $examen['duree_minutes'] % 60;
                $html .= $duree_heures . 'h' . ($duree_minutes < 10 ? '0' : '') . $duree_minutes;
                $html .= '</span>';
                $html .= '</div>';
                $html .= '</div>';
                
                // عرض القاعات
                $html .= '<div style="display: grid; gap: 1rem;">';
                foreach ($data_examen['salles'] as $salle_examen) {
                    // أيقونة القاعة حسب النوع
                    $salle_icon = '';
                    $badge_class = '';
                    $badge_text = '';
                    
                    switch($salle_examen['salle_type']) {
                        case 'amphi':
                            $salle_icon = '🏫';
                            $badge_class = 'amphi';
                            $badge_text = 'Amphithéâtre';
                            break;
                        case 'salle':
                            $salle_icon = '🏢';
                            $badge_class = 'salle';
                            $badge_text = 'Salle';
                            break;
                        case 'labo':
                            $salle_icon = '🔬';
                            $badge_class = 'labo';
                            $badge_text = 'Laboratoire';
                            break;
                        default:
                            $salle_icon = '🏢';
                            $badge_class = 'salle';
                            $badge_text = 'Salle';
                    }
                    
                    $html .= '<div style="background: rgba(67, 97, 238, 0.05); border-left: 4px solid #4361ee; padding: 0.75rem; border-radius: 6px; display: flex; justify-content: space-between; align-items: center; transition: all 0.2s ease;">';
                    $html .= '<div style="display: flex; align-items: center; gap: 0.75rem;">';
                    $html .= '<span style="font-size: 1.25rem; width: 30px; text-align: center;">' . $salle_icon . '</span>';
                    $html .= '<span style="font-weight: 600; color: #333;">' . htmlspecialchars($salle_examen['salle_nom']) . '</span>';
                    $html .= '<span style="font-size: 0.85rem; padding: 0.25rem 0.5rem; border-radius: 4px; font-weight: 600; ';
                    
                    if ($salle_examen['salle_type'] === 'amphi') {
                        $html .= 'background: rgba(155, 89, 182, 0.1); color: #9b59b6;">';
                    } elseif ($salle_examen['salle_type'] === 'salle') {
                        $html .= 'background: rgba(46, 204, 113, 0.1); color: #2ecc71;">';
                    } else {
                        $html .= 'background: rgba(52, 152, 219, 0.1); color: #3498db;">';
                    }
                    
                    $html .= $badge_text;
                    $html .= '</span>';
                    $html .= '</div>';
                    $html .= '<div style="background: rgba(46, 204, 113, 0.1); border-radius: 4px; padding: 0.5rem 0.75rem; font-size: 0.9rem; font-weight: 600; color: #2ecc71; display: flex; align-items: center; gap: 0.5rem;">';
                    $html .= '<i class="fas fa-users"></i>';
                    $html .= 'Groupes : ' . ($salle_examen['groupes'] ?? 'Tous');
                    $html .= '</div>';
                    $html .= '</div>';
                }
                $html .= '</div>';
                
                $html .= '</div>';
            }
            
            $html .= '</td>';
            $html .= '</tr>';
        }
        
        $html .= '</tbody>';
        $html .= '</table>';
        $html .= '</div>';
    }
    
    $html .= '</div>';
    
    return $html;
}