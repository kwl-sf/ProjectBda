<?php


require 'db.php';

// index.php - Page d'accueil
require_once 'includes/config.php';
require_once 'includes/functions.php';

// Si connecté, rediriger vers le dashboard
if (is_logged_in()) {
    $role = $_SESSION['user_role'];
    $dashboard_path = $role === 'etudiant' ? 'etudiant/dashboard.php' : $role . '/dashboard.php';
    redirect($dashboard_path);
}
?>
<!DOCTYPE html>
<html lang="fr" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Accueil | <?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --gradient-1: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            --gradient-2: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            --gradient-3: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            --gradient-4: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%);
        }
        
        .hero {
            min-height: 100vh;
            background: var(--gradient-1);
            position: relative;
            overflow: hidden;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            text-align: center;
            padding: 2rem;
        }
        
        .hero-content {
            position: relative;
            z-index: 2;
            max-width: 1200px;
            margin: 0 auto;
        }
        
        .hero h1 {
            font-size: 4rem;
            font-weight: 800;
            margin-bottom: 1.5rem;
            line-height: 1.2;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.3);
            animation: slideInLeft 0.8s ease-out;
        }
        
        .hero h1 .highlight {
            background: linear-gradient(135deg, #ffd166, #06d6a0);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .hero p {
            font-size: 1.5rem;
            margin-bottom: 2rem;
            opacity: 0.9;
            max-width: 800px;
            margin-left: auto;
            margin-right: auto;
            animation: slideInRight 0.8s ease-out 0.2s both;
        }
        
        .hero-buttons {
            display: flex;
            gap: 1rem;
            justify-content: center;
            margin-bottom: 3rem;
            animation: fadeIn 0.8s ease-out 0.4s both;
        }
        
        .btn-hero {
            padding: 1rem 2.5rem;
            font-size: 1.1rem;
            border-radius: 50px;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.75rem;
            backdrop-filter: blur(10px);
            border: 2px solid rgba(255,255,255,0.2);
        }
        
        .btn-hero-primary {
            background: rgba(255,255,255,0.15);
            color: white;
        }
        
        .btn-hero-secondary {
            background: transparent;
            color: white;
        }
        
        .btn-hero:hover {
            transform: translateY(-5px);
            box-shadow: 0 20px 40px rgba(0,0,0,0.2);
            border-color: rgba(255,255,255,0.4);
        }
        
        .features {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 2rem;
            margin-top: 4rem;
        }
        
        .feature-card {
            background: rgba(255,255,255,0.1);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            padding: 2rem;
            border: 1px solid rgba(255,255,255,0.2);
            transition: all 0.3s ease;
        }
        
        .feature-card:hover {
            transform: translateY(-10px);
            background: rgba(255,255,255,0.2);
            border-color: rgba(255,255,255,0.3);
        }
        
        .feature-icon {
            font-size: 3rem;
            margin-bottom: 1.5rem;
            display: inline-block;
            background: linear-gradient(135deg, #fff, #e0e0e0);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .feature-card h3 {
            font-size: 1.5rem;
            margin-bottom: 1rem;
        }
        
        .feature-card p {
            opacity: 0.9;
            font-size: 1rem;
        }
        
        .floating-elements {
            position: absolute;
            width: 100%;
            height: 100%;
            top: 0;
            left: 0;
            pointer-events: none;
        }
        
        .floating-element {
            position: absolute;
            width: 50px;
            height: 50px;
            background: rgba(255,255,255,0.1);
            border-radius: 15px;
            animation: float 6s ease-in-out infinite;
        }
        
        @keyframes float {
            0%, 100% { transform: translateY(0) rotate(0deg); }
            50% { transform: translateY(-20px) rotate(180deg); }
        }
        
        .stats {
            display: flex;
            justify-content: center;
            gap: 3rem;
            margin: 3rem 0;
            flex-wrap: wrap;
        }
        
        .stat-item {
            text-align: center;
        }
        
        .stat-number {
            font-size: 3rem;
            font-weight: 800;
            display: block;
            background: linear-gradient(135deg, #ffd166, #06d6a0);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .stat-label {
            font-size: 1rem;
            opacity: 0.8;
            margin-top: 0.5rem;
        }
        
        .demo-badge {
            position: fixed;
            top: 20px;
            right: 20px;
            background: rgba(255,255,255,0.15);
            padding: 0.75rem 1.5rem;
            border-radius: 50px;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255,255,255,0.2);
            z-index: 1000;
            animation: pulse 2s infinite;
        }
        
        @media (max-width: 768px) {
            .hero h1 {
                font-size: 2.5rem;
            }
            
            .hero p {
                font-size: 1.2rem;
            }
            
            .hero-buttons {
                flex-direction: column;
                align-items: center;
            }
            
            .btn-hero {
                width: 100%;
                max-width: 300px;
                justify-content: center;
            }
        }
    </style>
</head>
<body>
    <div class="demo-badge">
        <i class="fas fa-rocket"></i> Version Démo - Projet Universitaire
    </div>
    
    <section class="hero">
        <div class="floating-elements">
            <div class="floating-element" style="top: 10%; left: 10%; animation-delay: 0s;"></div>
            <div class="floating-element" style="top: 20%; right: 15%; animation-delay: 1s;"></div>
            <div class="floating-element" style="bottom: 30%; left: 20%; animation-delay: 2s;"></div>
            <div class="floating-element" style="bottom: 20%; right: 10%; animation-delay: 3s;"></div>
        </div>
        
        <div class="hero-content">
            <h1>
                <span class="highlight">Plateforme des examens</span><br>
                L'Intelligence Artificielle<br>
                au service de l’Université
            </h1>
            
            <p>
                Système révolutionnaire de planification des examens universitaires.<br>
                Plus rapide, plus intelligent, plus efficace.
            </p>
            
            <div class="stats">
                <div class="stat-item">
                    <span class="stat-number">45s</span>
                    <span class="stat-label">Génération des horaires</span>
                </div>
                <div class="stat-item">
                    <span class="stat-number">0%</span>
                    <span class="stat-label">Conflits détectés</span>
                </div>
                <div class="stat-item">
                    <span class="stat-number">13k+</span>
                    <span class="stat-label">Étudiants gérés</span>
                </div>
                <div class="stat-item">
                    <span class="stat-number">99.9%</span>
                    <span class="stat-label">Satisfaction</span>
                </div>
            </div>
            
            <div class="hero-buttons">
                <a href="login.php" class="btn-hero btn-hero-primary">
                    <i class="fas fa-sign-in-alt"></i> Se Connecter
                </a>
                <a href="#features" class="btn-hero btn-hero-secondary">
                    <i class="fas fa-play-circle"></i> Voir la Démo
                </a>
            </div>
            
            <div class="features" id="features">
                <div class="feature-card">
                    <div class="feature-icon">
                        <i class="fas fa-brain"></i>
                    </div>
                    <h3>Algorithme Intelligent</h3>
                    <p>Génération automatique des emplois du temps avec optimisation IA en moins de 45 secondes.</p>
                </div>
                
                <div class="feature-card">
                    <div class="feature-icon">
                        <i class="fas fa-bolt"></i>
                    </div>
                    <h3>Détection en Temps Réel</h3>
                    <p>Surveillance continue et détection automatique des conflits et chevauchements.</p>
                </div>
                
                <div class="feature-card">
                    <div class="feature-icon">
                        <i class="fas fa-chart-network"></i>
                    </div>
                    <h3>Tableaux de Bord Avancés</h3>
                    <p>Visualisation des données et KPIs pour chaque acteur du système éducatif.</p>
                </div>
            </div>
        </div>
    </section>
    
    <script>
        // Animation des éléments flottants
        document.addEventListener('DOMContentLoaded', function() {
            // Effet de parallaxe
            window.addEventListener('scroll', function() {
                const scrolled = window.pageYOffset;
                const elements = document.querySelectorAll('.floating-element');
                
                elements.forEach((element, index) => {
                    const speed = 0.3 + (index * 0.1);
                    const yPos = -(scrolled * speed);
                    element.style.transform = `translateY(${yPos}px) rotate(${scrolled * 0.1}deg)`;
                });
            });
            
            // Animation au défilement
            const observer = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        entry.target.style.opacity = 1;
                        entry.target.style.transform = 'translateY(0)';
                    }
                });
            }, { threshold: 0.1 });
            
            document.querySelectorAll('.feature-card').forEach(card => {
                card.style.opacity = 0;
                card.style.transform = 'translateY(50px)';
                card.style.transition = 'all 0.6s ease';
                observer.observe(card);
            });
        });
    </script>
</body>
</html>