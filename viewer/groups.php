<?php
require_once '../includes/config.php';

if (!isLoggedIn()) {
    header('Location: ../login.php');
    exit;
}

// Get current published groups
$publishedGroups = $pdo->query("SELECT * FROM groups WHERE is_published = true ORDER BY created_at DESC")->fetchAll();

$groupsData = [];
if (!empty($publishedGroups)) {
    foreach ($publishedGroups as $group) {
        $stmt = $pdo->prepare("
            SELECT s.* FROM singers s
            JOIN group_assignments ga ON s.id = ga.singer_id
            WHERE ga.group_id = ?
            ORDER BY s.voice_category, s.voice_level DESC, s.full_name
        ");
        $stmt->execute([$group['id']]);
        $singers = $stmt->fetchAll();

        $voiceData = [];
        foreach ($singers as $singer) {
            $voiceData[$singer['voice_category']][] = $singer;
        }

        $groupsData[] = [
            'group' => $group,
            'voiceData' => $voiceData
        ];
    }
}

// Get recent logs for transparency
$recentLogs = $pdo->query("
    SELECT l.*, u.username
    FROM logs l
    LEFT JOIN users u ON l.user_id = u.id
    ORDER BY l.created_at DESC
    LIMIT 10
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Groups - Reverence Worship Team</title>
    <link rel="stylesheet" href="../css/style.css">
</head>
<body>
    <header>
        <div class="header-content">
            <div class="logo-container">
                <img src="../assets/Logo Reverence-Photoroom.png" alt="Reverence WorshipTeam Logo" class="logo">
            </div>
            <div class="title-container">
                <h1>Reverence WorshipTeam</h1>
                <h2>Current Group Assignments</h2>
            </div>
        </div>
        <nav>
            <a href="../index.php">Home</a>
            <a href="history.php">Group History</a>
            <a href="../logout.php">Logout</a>
        </nav>
    </header>

    <main>
        <div class="welcome-message">
            <h2>Hello, <?php echo htmlspecialchars($_SESSION['username']); ?>!</h2>
            <p>Here are the current group assignments. Remember: Fairness, balance, transparency, worship-first.</p>
        </div>

        <?php if (!empty($groupsData)): ?>
            <?php foreach ($groupsData as $groupData): ?>
                <div class="group">
                    <h3><?php echo htmlspecialchars($groupData['group']['name']); ?> - <?php echo date('F j, Y', strtotime($groupData['group']['service_date'] ?? $groupData['group']['created_at'])); ?></h3>
                    <p><em>Created: <?php echo date('F j, Y \a\t g:i A', strtotime($groupData['group']['created_at'])); ?></em></p>

                    <div class="voice-categories">
                        <?php
                        $voices = ['Soprano', 'Alto', 'Tenor', 'Bass'];
                        foreach ($voices as $voice): ?>
                            <div class="voice-category">
                                <h4><?php echo $voice; ?>s</h4>
                                <?php if (isset($groupData['voiceData'][$voice])): ?>
                                    <div class="singer-list">
                                        <?php
                                        $goodSingers = array_filter($groupData['voiceData'][$voice], fn($s) => $s['voice_level'] === 'Good');
                                        $normalSingers = array_filter($groupData['voiceData'][$voice], fn($s) => $s['voice_level'] === 'Normal');
                                        ?>

                                        <?php if (!empty($goodSingers)): ?>
                                            <div class="level-group">
                                                <h5>Strong / Experienced:</h5>
                                                <ul>
                                                    <?php $singerNum = 1; ?>
                                                    <?php foreach ($goodSingers as $singer): ?>
                                                        <li><?php echo $singerNum; ?>. <?php echo htmlspecialchars($singer['full_name']); ?></li>
                                                    <?php $singerNum++; ?>
                                                    <?php endforeach; ?>
                                                </ul>
                                            </div>
                                        <?php endif; ?>

                                        <?php if (!empty($normalSingers)): ?>
                                            <div class="level-group">
                                                <h5>Average / Developing:</h5>
                                                <ul>
                                                    <?php $singerNum = 1; ?>
                                                    <?php foreach ($normalSingers as $singer): ?>
                                                        <li><?php echo $singerNum; ?>. <?php echo htmlspecialchars($singer['full_name']); ?></li>
                                                    <?php $singerNum++; ?>
                                                    <?php endforeach; ?>
                                                </ul>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php else: ?>
                                    <p><em>No <?php echo strtolower($voice); ?>s assigned</em></p>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="group-stats">
                        <p><strong>Total singers:</strong> <?php
                            $total = 0;
                            foreach ($groupData['voiceData'] as $voice => $singers) {
                                $total += count($singers);
                            }
                            echo $total;
                        ?></p>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="no-groups">
                <h3>No Groups Published</h3>
                <p>There are currently no published group assignments. Please check back later.</p>
            </div>
        <?php endif; ?>

        <div class="transparency-section">
            <h3>Recent Activity (Transparency Log)</h3>
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Action</th>
                            <th>Details</th>
                            <th>User</th>
                            <th>When</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentLogs as $log): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($log['action']); ?></td>
                                <td><?php echo htmlspecialchars($log['details'] ?: 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($log['username'] ?: 'System'); ?></td>
                                <td><?php echo date('M j, g:i A', strtotime($log['created_at'])); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p class="transparency-note">
                <em>All changes to the system are logged and visible to maintain transparency and fairness.</em>
            </p>
        </div>
    </main>

    <footer>
        <div class="footer-content">
            <div class="footer-brand">
                <img src="../assets/Logo Reverence-Photoroom.png" alt="Reverence WorshipTeam Logo" class="footer-logo">
                <h3>Reverence WorshipTeam</h3>
                <p>Transparent and fair gospel choir group assignments.</p>
            </div>

            <div class="footer-section footer-scripture">
                <blockquote>
                    <p><strong>Psalm 96:7-9</strong></p>
                    <p>Give praise to the Lord, you who belong to all peoples, give glory to him and take up his praise.</p>
                </blockquote>
            </div>

            <div class="footer-section">
                <h4>Quick Links</h4>
                <p><a href="../index.php">Home</a></p>
                <p><a href="groups.php">Current Groups</a></p>
                <p><a href="history.php">Group History</a></p>
                <p><a href="../logout.php">Logout</a></p>
            </div>

            <div class="footer-section">
                <h4>Contact</h4>
                <p><strong>Email:</strong><br>worshipteamkicukiro@gmail.com</p>
                <p><strong>Address:</strong><br>23JX +43M, Kicukiro<br>Kigali, Rwanda</p>
                <p><strong>Phone:</strong><br>+250788880574<br>0784462768<br>0781520618</p>
            </div>
        </div>
        <div class="footer-bottom">
            <p>&copy; 2026 Reverence WorshipTeam. All rights reserved. Elevating worship experiences worldwide.</p>
        </div>
    </footer>

    <script src="../js/main.js"></script>
</body>
</html>
