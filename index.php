<?php
session_start();
require_once 'includes/config.php';

// Check if user is logged in
$isLoggedIn = isset($_SESSION['user_id']);
$userRole = $isLoggedIn ? $_SESSION['user_role'] : null;

// Get all published groups for display
$publishedGroups = [];
$allGroups = [];

try {
    $stmt = $pdo->query("SELECT * FROM groups WHERE is_published = true ORDER BY created_at DESC");
    $publishedGroups = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($publishedGroups)) {
        // Get all singers for all published groups
        $groupIds = array_column($publishedGroups, 'id');
        $placeholders = str_repeat('?,', count($groupIds) - 1) . '?';

        $stmt = $pdo->prepare("
            SELECT g.name as group_name, g.service_date as group_date, s.*
            FROM group_assignments ga
            JOIN groups g ON ga.group_id = g.id
            JOIN singers s ON ga.singer_id = s.id
            WHERE g.id IN ($placeholders) AND g.is_published = true
            ORDER BY g.service_date DESC, g.name, s.voice_category, s.voice_level DESC
        ");
        $stmt->execute($groupIds);
        $allGroups = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    // Handle database errors gracefully
    $error = "Unable to load group information.";
}

// Get active landing images for background slideshow
$backgroundImages = [];
try {
    $stmt = $pdo->query("SELECT image_path FROM landing_images WHERE is_active = true ORDER BY created_at DESC");
    $images = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($images as $image) {
        // Convert admin-relative path to root-relative path
        // Admin stores paths as ../uploads/images/filename.jpg
        // From root, this should be uploads/images/filename.jpg
        $rootPath = str_replace('../', '', $image['image_path']);
        if (file_exists($rootPath)) {
            $backgroundImages[] = $rootPath;
        }
    }
} catch (PDOException $e) {
    // Fallback if database error
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reverence WorshipTeam</title>
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/index.css">
    <style>
        /* Dynamic CSS for background slideshow */
        .welcome-section {
            position: relative;
            overflow: hidden;
        }

        .welcome-section .bg-slideshow {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
            opacity: 1;
            transition: opacity 2s ease-in-out;
        }

        <?php if (!empty($backgroundImages)): ?>
        <?php foreach ($backgroundImages as $index => $image): ?>
        .welcome-section .bg-slide-<?php echo $index; ?> {
            background-image: url('<?php echo htmlspecialchars($image); ?>');
        }
        <?php endforeach; ?>

        /* Simple slideshow with 10-second intervals */
        .welcome-section .bg-slide-0 {
            animation: fadeInOut 20s infinite;
        }

        <?php if (count($backgroundImages) > 1): ?>
        .welcome-section .bg-slide-1 {
            animation: fadeInOut 20s infinite 10s;
        }
        <?php endif; ?>

        @keyframes fadeInOut {
            0%, 100% { opacity: 0; }
            10%, 45% { opacity: 1; }
            55%, 90% { opacity: 0; }
        }
        <?php endif; ?>
    </style>
</head>
<body>
    <header class="welcome-header">
        <div class="header-content">
            <div class="logo-container">
                <img src="assets/Logo Reverence-Photoroom.png" alt="Reverence WorshipTeam Logo" class="logo">
            </div>
            <div class="title-container">
                <h1>Reverence WorshipTeam</h1>
            </div>
        </div>
        <nav class="welcome-nav">
            <a href="login.php">Login</a>
        </nav>
    </header>

    <section class="welcome-section <?php echo !empty($backgroundImages) ? 'has-dots' : ''; ?>">
        <?php if (!empty($backgroundImages)): ?>
            <?php foreach ($backgroundImages as $index => $image): ?>
                <div class="bg-slideshow bg-slide-<?php echo $index; ?> <?php echo $index === 0 ? 'active' : ''; ?>"></div>
            <?php endforeach; ?>

            <!-- Navigation Dots -->
            <div class="slideshow-dots">
                <?php for ($i = 0; $i < count($backgroundImages); $i++): ?>
                    <span class="dot <?php echo $i === 0 ? 'active' : ''; ?>" data-slide="<?php echo $i; ?>"></span>
                <?php endfor; ?>
            </div>
        <?php endif; ?>

        <div class="welcome-content">
            <h1>REVERENCE</h1>
            <h2>Worship Team</h2>
            <div class="scripture">
                <p><em>"Therefore, since we are receiving a kingdom that cannot be shaken, let us be thankful, and so worship God acceptably with reverence and awe."</em></p>
                <p><strong>Hebrews 12:28</strong></p>
            </div>
        </div>
    </section>

    <main>

        <?php if (!empty($publishedGroups) && !empty($allGroups)): ?>
            <section class="groups-section">
                <h3>All Published Groups</h3>
                <div class="current-groups">
                    <?php
                    // Group singers by group name and date
                    $groupedData = [];
                    foreach ($allGroups as $singer) {
                        $groupKey = $singer['group_name'] . '|' . $singer['group_date'];
                        $groupedData[$groupKey][] = $singer;
                    }

                    foreach ($groupedData as $groupKey => $singers):
                        list($groupName, $groupDate) = explode('|', $groupKey);
                        $formattedDate = date('M j, Y', strtotime($groupDate));
                    ?>
                    <div class="group">
                        <h4><?php echo htmlspecialchars($groupName); ?> <small>(<?php echo $formattedDate; ?>)</small></h4>
                                    <div class="voice-categories">
                                        <?php
                                        $voices = ['Soprano', 'Alto', 'Tenor', 'Bass'];
                                        foreach ($voices as $voice):
                                            $voiceSingers = array_filter($singers, function($s) use ($voice) {
                                                return $s['voice_category'] === $voice;
                                            });
                                        ?>
                                            <div class="voice-category">
                                                <h5><?php echo $voice; ?> <span class="count">(<?php echo count($voiceSingers); ?>)</span></h5>
                                                <ul>
                                                    <?php $singerNum = 1; ?>
                                                    <?php foreach ($voiceSingers as $singer): ?>
                                                        <li><?php echo $singerNum; ?>. <?php echo htmlspecialchars($singer['full_name']); ?> </li>
                                                    <?php $singerNum++; ?>
                                                    <?php endforeach; ?>
                                                </ul>
                                            </div>
                                        <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php else: ?>
            <section class="groups-section">
                <div class="no-groups-message">
                    <h3> Welcome Worship Team</h3>
                    <p>Groups will be displayed here once the administrator creates and publishes </p>
                </div>
            </section>
        <?php endif; ?>
    </main>

    <footer>
        <div class="footer-content">
            <div class="footer-brand">
             
                <h3>Reverence WorshipTeam</h3>
               
            </div>

            <div class="footer-section footer-scripture">
                <blockquote>
                    <p><strong>Psalm 96:7-9</strong></p>
                    <p>Give praise to the Lord, you who belong to all peoples, give glory to him and take up his praise.</p>
                </blockquote>
            </div>

            

            <div class="footer-section">
                <h4>Contact Information</h4>
                <p><strong>Location:</strong><br>Kicukiro, Kigali, Rwanda</p>
                <p><strong>Email:</strong><br>worshipteamkicukiro@gmail.com</p>
                <p><strong>Phone:</strong><br>+250788880574</p>
                <p><a href="https://www.reverenceworshipteam.com" target="_blank">🌐 Official Website</a></p>
            </div>
        </div>

        <div class="footer-bottom">
            <div class="copyright">
                <p>&copy; 2026 Reverence WorshipTeam. All rights reserved.</p>
            </div>
        </div>
    </footer>

    <script src="js/main.js"></script>
    <script>
        // Slideshow dot navigation
        document.addEventListener('DOMContentLoaded', function() {
            const dots = document.querySelectorAll('.dot');
            const slides = document.querySelectorAll('.bg-slideshow');

            if (dots.length > 0 && slides.length > 0) {
                // Function to show specific slide
                function showSlide(slideIndex) {
                    // Hide all slides
                    slides.forEach(slide => slide.classList.remove('active'));
                    dots.forEach(dot => dot.classList.remove('active'));

                    // Show selected slide
                    slides[slideIndex].classList.add('active');
                    dots[slideIndex].classList.add('active');
                }

                // Add click event to dots
                dots.forEach((dot, index) => {
                    dot.addEventListener('click', function() {
                        showSlide(index);
                    });
                });

                // Auto-play functionality (optional, can be disabled)
                let currentSlide = 0;
                const autoPlayInterval = 8000; // 8 seconds

                function autoPlay() {
                    currentSlide = (currentSlide + 1) % slides.length;
                    showSlide(currentSlide);
                }

                // Start auto-play
                let autoPlayTimer = setInterval(autoPlay, autoPlayInterval);

                // Pause auto-play when user interacts with dots
                dots.forEach(dot => {
                    dot.addEventListener('click', function() {
                        clearInterval(autoPlayTimer);
                        // Restart auto-play after user interaction
                        setTimeout(() => {
                            autoPlayTimer = setInterval(autoPlay, autoPlayInterval);
                        }, autoPlayInterval);
                    });
                });
            }
        });
    </script>
</body>
</html>
