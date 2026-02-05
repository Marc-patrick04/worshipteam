<?php
/**
 * Groups Management System
 * Handles creation, management, and assignment of worship groups
 */

// Start session and include config
require_once '../includes/config.php';

// Authentication check
if (!isLoggedIn() || !isAdmin()) {
    header('Location: ../login.php');
    exit;
}

// Initialize variables
$message = '';
$errors = [];
$warnings = [];
$action = $_GET['action'] ?? 'list';
$editId = $_GET['id'] ?? null;
$viewId = $_GET['id'] ?? null;
$assignId = $_GET['id'] ?? null;
$movementsId = $_GET['id'] ?? null;

// Initialize data objects
$editGroup = null;
$viewGroup = null;
$assignGroup = null;
$movementHistory = null;

/**
 * Error and Message Handling Functions
 */
function addError($error) {
    global $errors;
    $errors[] = $error;
}

function addWarning($warning) {
    global $warnings;
    $warnings[] = $warning;
}

function setMessage($msg, $type = 'success') {
    global $message;
    $message = $msg;
}

/**
 * Data Retrieval Functions
 */
function getPublishedGroups() {
    global $pdo;
    try {
        return $pdo->query("SELECT * FROM groups WHERE is_published = true ORDER BY service_date DESC, service_order ASC")->fetchAll();
    } catch (PDOException $e) {
        addError("Database error retrieving published groups: " . $e->getMessage());
        return [];
    }
}

function getAllGroups() {
    global $pdo;
    try {
        return $pdo->query("
            SELECT g.*, u.username as creator
            FROM groups g
            LEFT JOIN users u ON g.created_by = u.id
            ORDER BY g.service_date DESC, g.service_order ASC
            LIMIT 50
        ")->fetchAll();
    } catch (PDOException $e) {
        addError("Database error retrieving group history: " . $e->getMessage());
        return [];
    }
}

function getGroupById($id) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("SELECT * FROM groups WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch();
    } catch (PDOException $e) {
        addError("Database error retrieving group: " . $e->getMessage());
        return null;
    }
}

function getActiveSingers() {
    global $pdo;
    try {
        return $pdo->query("SELECT * FROM singers WHERE status = 'Active' ORDER BY voice_category, voice_level DESC, full_name")->fetchAll();
    } catch (PDOException $e) {
        addError("Database error retrieving singers: " . $e->getMessage());
        return [];
    }
}

/**
 * Group Management Functions
 */
function validateGroupCreation($groupCount, $groupNames, $serviceDate, $mixingMethod) {
    // Check for existing groups on this date
    global $pdo;
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM groups WHERE service_date = ?");
        $stmt->execute([$serviceDate]);
        $existingCount = $stmt->fetch()['count'];

        if ($existingCount > 0) {
            addError("Groups already exist for date {$serviceDate}. Please choose a different date.");
            return false;
        }
    } catch (PDOException $e) {
        addError("Database error checking existing groups: " . $e->getMessage());
        return false;
    }

    // Validate inputs
    if ($groupCount < 1 || $groupCount > 10) {
        addError("Number of groups must be between 1 and 10.");
        return false;
    }

    if (empty($serviceDate)) {
        addError("Service date is required.");
        return false;
    }

    if (empty($mixingMethod)) {
        addError("Please select a mixing method.");
        return false;
    }

    // Validate group names
    if (count($groupNames) !== $groupCount) {
        addError("Number of group names must match number of groups.");
        return false;
    }

    foreach ($groupNames as $name) {
        if (empty(trim($name))) {
            addError("All group names are required and cannot be empty.");
            return false;
        }
    }

    return true;
}

function createGroups($groupCount, $groupNames, $serviceDate, $mixingMethod) {
    global $pdo;

    // Get mixing algorithm result
    $result = runMixingAlgorithm($groupCount, $mixingMethod);
    if (!$result['success']) {
        addError($result['message']);
        return false;
    }

    // Validate complete assignment - count unique singers (skip for manual assignment)
    if ($mixingMethod !== 'manual') {
        $totalSingers = count(getActiveSingers());
        $assignedSingersSet = [];
        foreach ($result['assignments'] as $group) {
            foreach ($group as $singerId) {
                $assignedSingersSet[$singerId] = true; // Use as set to avoid duplicates
            }
        }
        $assignedSingers = count($assignedSingersSet);

        if ($assignedSingers !== $totalSingers) {
            addError("Assignment algorithm failed: {$assignedSingers} singers assigned, {$totalSingers} total active singers.");
            return false;
        }
    }

    // Create groups in database
    $pdo->beginTransaction();
    try {
        // Unpublish existing groups
        $pdo->exec("UPDATE groups SET is_published = false WHERE is_published = true");

        $groupIds = [];
        foreach ($groupNames as $index => $name) {
            $serviceOrder = $index + 1;
            $stmt = $pdo->prepare("
                INSERT INTO groups (name, service_date, service_order, is_published, created_by)
                VALUES (?, ?, ?, false, ?)
            ");
            $stmt->execute([$name, $serviceDate, $serviceOrder, $_SESSION['user_id']]);
            $groupIds[] = $pdo->lastInsertId();
        }

        // Assign singers to groups
        foreach ($result['assignments'] as $groupIndex => $singers) {
            foreach ($singers as $singerId) {
                $stmt = $pdo->prepare("INSERT INTO group_assignments (group_id, singer_id) VALUES (?, ?)");
                $stmt->execute([$groupIds[$groupIndex], $singerId]);
            }
        }

        $pdo->commit();

        // Different message for manual assignment
        if ($mixingMethod === 'manual') {
            setMessage("✅ Empty groups created successfully! {$groupCount} groups are ready for manual singer assignment. Groups are saved as drafts - publish them when ready.");
        } else {
            setMessage("✅ Groups created successfully! {$assignedSingers} singers assigned across {$groupCount} groups. Groups are saved as drafts - publish them when ready.");
        }

        logAction('create_groups', "Created {$groupCount} groups for {$serviceDate}: " . implode(', ', $groupNames));
        return true;

    } catch (PDOException $e) {
        $pdo->rollBack();
        addError("Database error creating groups: " . $e->getMessage());
        return false;
    }
}

/**
 * Load Data Based on Action
 */
if ($action === 'edit' && $editId) {
    $editGroup = getGroupById($editId);
    if (!$editGroup) {
        $action = 'list';
    }
} elseif ($action === 'view' && $viewId) {
    $viewGroup = getGroupById($viewId);
    if (!$viewGroup) {
        $action = 'list';
    }
} elseif ($action === 'assign' && $assignId) {
    $assignGroup = getGroupById($assignId);
    if (!$assignGroup) {
        $action = 'list';
    }
} elseif ($action === 'movements') {
    $movementHistory = getGroupMovementHistory($movementsId);
    if (!$movementHistory) {
        // If movement history fails to load, redirect to published groups with error
        addError("Unable to load movement history. The database table may not exist yet.");
        $action = 'list';
    }
}

/**
 * Handle Form Submissions
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['create_groups'])) {
        $groupCount = (int)($_POST['group_count'] ?? 0);
        $groupNames = $_POST['group_names'] ?? [];
        $serviceDate = $_POST['service_date'] ?? '';
        $mixingMethod = $_POST['mixing_method'] ?? '';

        if (validateGroupCreation($groupCount, $groupNames, $serviceDate, $mixingMethod)) {
            createGroups($groupCount, $groupNames, $serviceDate, $mixingMethod);
        }
    }
    elseif (isset($_POST['publish_groups'])) {
        $groupId = $_POST['group_id'];
        try {
            $stmt = $pdo->prepare("SELECT is_published FROM groups WHERE id = ?");
            $stmt->execute([$groupId]);
            $current = $stmt->fetch();

            if ($current !== false) {
                // Convert to boolean explicitly
                $isCurrentlyPublished = (bool)$current['is_published'];
                $newStatus = $isCurrentlyPublished ? 0 : 1; // Use 0/1 for PostgreSQL boolean

                $stmt = $pdo->prepare("UPDATE groups SET is_published = ? WHERE id = ?");
                $stmt->execute([$newStatus, $groupId]);

                $actionText = $newStatus ? 'published' : 'unpublished';
                setMessage("Group {$actionText} successfully!");
                logAction('publish_groups', ucfirst($actionText) . " group ID: {$groupId}");
            } else {
                addError('Group not found.');
            }
        } catch (PDOException $e) {
            addError('Error updating group status: ' . $e->getMessage());
        }
    }
    elseif (isset($_POST['delete_groups'])) {
        $groupId = $_POST['group_id'];
        try {
            $stmt = $pdo->prepare("DELETE FROM groups WHERE id = ?");
            $stmt->execute([$groupId]);
            setMessage('Groups deleted successfully!');
            logAction('delete_groups', "Deleted group ID: {$groupId}");
        } catch (PDOException $e) {
            addError('Error deleting groups: ' . $e->getMessage());
        }
    }
    elseif (isset($_POST['update_group'])) {
        $groupId = $_POST['group_id'];
        $groupName = trim($_POST['group_name']);
        $serviceDate = $_POST['service_date'];
        $serviceOrder = (int)$_POST['service_order'];
        $isPublished = isset($_POST['is_published']) ? true : false;

        if (empty($groupName)) {
            addError('Group name is required.');
        } elseif (empty($serviceDate)) {
            addError('Service date is required.');
        } elseif ($serviceOrder < 1) {
            addError('Service order must be 1 or greater.');
        } else {
            try {
                $stmt = $pdo->prepare("
                    UPDATE groups
                    SET name = ?, service_date = ?, service_order = ?, is_published = ?
                    WHERE id = ?
                ");
                $stmt->execute([$groupName, $serviceDate, $serviceOrder, $isPublished, $groupId]);
                setMessage('Group updated successfully!');
                logAction('update_group', "Updated group ID: {$groupId}");
                header('Location: groups.php?action=history');
                exit;
            } catch (PDOException $e) {
                addError('Error updating group: ' . $e->getMessage());
            }
        }
    }
    elseif (isset($_POST['update_assignments'])) {
        $groupId = $_POST['group_id'];
        $selectedSingers = $_POST['singers'] ?? [];

        try {
            $pdo->beginTransaction();

            // Track assignment changes before making updates
            trackAssignmentChanges($groupId, $selectedSingers);

            // Remove all current assignments for this group
            $stmt = $pdo->prepare("DELETE FROM group_assignments WHERE group_id = ?");
            $stmt->execute([$groupId]);

            // Add new assignments
            $assignedCount = 0;
            foreach ($selectedSingers as $singerId) {
                $stmt = $pdo->prepare("INSERT INTO group_assignments (group_id, singer_id) VALUES (?, ?)");
                $stmt->execute([$groupId, $singerId]);
                $assignedCount++;
            }

            $pdo->commit();
            setMessage("Singer assignments updated successfully! {$assignedCount} singers assigned.");
            logAction('update_assignments', "Updated assignments for group ID: {$groupId}");
        } catch (PDOException $e) {
            $pdo->rollBack();
            addError('Error updating assignments: ' . $e->getMessage());
        }
    }
}

/**
 * Load Display Data
 */
$publishedGroups = getPublishedGroups();
$allGroups = getAllGroups();

/**
 * Algorithm Functions
 */
function runMixingAlgorithm($numGroups, $mixingMethod = 'rotation') {
    $singers = getActiveSingers();

    if (empty($singers)) {
        return ['success' => false, 'message' => 'No active singers found.'];
    }

    // Get all singer IDs for distribution
    $allSingerIds = array_column($singers, 'id');

    // Choose distribution function
    $distributionFunction = match($mixingMethod) {
        'stable_core' => 'distributeSingersWithStableCore',
        'rotation' => 'distributeSingersWithRotation',
        'balanced' => 'distributeSingersEvenly',
        'random' => 'distributeSingersRandomly',
        'manual' => 'distributeSingersManual',
        default => 'distributeSingersWithRotation'
    };

    // Distribute ALL singers at once
    $assignments = $distributionFunction($allSingerIds, array_fill(0, $numGroups, []), []);

    return [
        'success' => true,
        'assignments' => $assignments,
        'warnings' => [],
        'group_sizes' => array_map('count', $assignments)
    ];
}

function distributeSingersWithStableCore($singerIds, $currentAssignments, $singerHistory) {
    $numGroups = count($currentAssignments);
    $singerIds = array_values($singerIds);

    // Start with empty groups
    $result = array_fill(0, $numGroups, []);

    // Simple round-robin distribution WITHOUT overlap
    // Each singer is assigned to exactly ONE group
    $groupIndex = 0;

    foreach ($singerIds as $singerId) {
        $result[$groupIndex % $numGroups][] = $singerId;
        $groupIndex++;
    }

    return $result;
}

function distributeSingersWithRotation($singerIds, $currentAssignments, $singerHistory) {
    $numGroups = count($currentAssignments);
    $singerIds = array_values($singerIds);

    $result = $currentAssignments;

    // Simple round-robin for now
    foreach ($singerIds as $index => $singerId) {
        $groupIndex = $index % $numGroups;
        $result[$groupIndex][] = $singerId;
    }

    return $result;
}

function distributeSingersEvenly($singerIds, $currentAssignments) {
    $numGroups = count($currentAssignments);
    $singerIds = array_values($singerIds);

    $result = $currentAssignments;

    foreach ($singerIds as $index => $singerId) {
        $groupIndex = $index % $numGroups;
        $result[$groupIndex][] = $singerId;
    }

    return $result;
}

function distributeSingersRandomly($singerIds, $currentAssignments) {
    $numGroups = count($currentAssignments);
    $singerIds = array_values($singerIds);

    global $pdo;

    // Get singer details for voice balancing
    $singersByVoice = [];
    $voiceCategories = ['Soprano', 'Alto', 'Tenor', 'Bass'];

    foreach ($singerIds as $singerId) {
        $stmt = $pdo->prepare("SELECT voice_category, voice_level FROM singers WHERE id = ?");
        $stmt->execute([$singerId]);
        $singer = $stmt->fetch();
        if ($singer) {
            $voice = $singer['voice_category'];
            $level = $singer['voice_level'];
            if (!isset($singersByVoice[$voice])) {
                $singersByVoice[$voice] = ['Good' => [], 'Normal' => []];
            }
            $singersByVoice[$voice][$level][] = $singerId;
        }
    }

    $result = array_fill(0, $numGroups, []);

    // Distribute singers by voice category for balanced distribution
    foreach ($voiceCategories as $voice) {
        if (!isset($singersByVoice[$voice])) continue;

        // First distribute Good singers of this voice evenly
        $goodSingers = $singersByVoice[$voice]['Good'];
        if (!empty($goodSingers)) {
            shuffle($goodSingers);
            $distributed = distributeEvenly($goodSingers, $numGroups);

            foreach ($distributed as $groupIndex => $groupSingers) {
                $result[$groupIndex] = array_merge($result[$groupIndex], $groupSingers);
            }
        }

        // Then distribute Normal singers of this voice evenly
        $normalSingers = $singersByVoice[$voice]['Normal'];
        if (!empty($normalSingers)) {
            shuffle($normalSingers);
            $distributed = distributeEvenly($normalSingers, $numGroups);

            foreach ($distributed as $groupIndex => $groupSingers) {
                $result[$groupIndex] = array_merge($result[$groupIndex], $groupSingers);
            }
        }
    }

    return $result;
}

// Helper function to distribute singers evenly across groups
function distributeEvenly($singers, $numGroups) {
    $result = array_fill(0, $numGroups, []);
    $totalSingers = count($singers);

    if ($totalSingers === 0) return $result;

    // Calculate base singers per group and remainder
    $basePerGroup = intdiv($totalSingers, $numGroups);
    $remainder = $totalSingers % $numGroups;

    $singerIndex = 0;

    // First, distribute the base amount to all groups
    for ($groupIndex = 0; $groupIndex < $numGroups; $groupIndex++) {
        for ($i = 0; $i < $basePerGroup; $i++) {
            if ($singerIndex < $totalSingers) {
                $result[$groupIndex][] = $singers[$singerIndex++];
            }
        }
    }

    // Then distribute the remainder (one extra singer to each group until remainder is exhausted)
    for ($i = 0; $i < $remainder; $i++) {
        if ($singerIndex < $totalSingers) {
            $result[$i][] = $singers[$singerIndex++];
        }
    }

    return $result;
}

function distributeSingersManual($singerIds, $currentAssignments) {
    // For manual assignment, create empty groups
    return $currentAssignments;
}

function getServiceTimeDisplay($serviceOrder) {
    $suffixes = ['th', 'st', 'nd', 'rd'];
    $value = $serviceOrder % 100;

    if ($value >= 11 && $value <= 13) {
        $suffix = 'th';
    } else {
        $suffix = $suffixes[$value % 10] ?? 'th';
    }

    return $serviceOrder . $suffix . ' Service';
}

/**
 * Movement History Functions
 */
function recordSingerMovement($singerId, $fromGroupId, $toGroupId, $movementType, $movementDate = null, $notes = '') {
    global $pdo;

    if (!$movementDate) {
        $movementDate = date('Y-m-d');
    }

    try {
        $stmt = $pdo->prepare("
            INSERT INTO singer_movement_history (singer_id, from_group_id, to_group_id, movement_date, movement_type, notes)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$singerId, $fromGroupId, $toGroupId, $movementDate, $movementType, $notes]);
    } catch (PDOException $e) {
        // Log error but don't fail the operation
        error_log("Failed to record singer movement: " . $e->getMessage());
    }
}

function trackAssignmentChanges($groupId, $newSingerIds) {
    global $pdo;

    try {
        // Get current assignments
        $stmt = $pdo->prepare("SELECT singer_id FROM group_assignments WHERE group_id = ?");
        $stmt->execute([$groupId]);
        $currentAssignments = array_column($stmt->fetchAll(), 'singer_id');

        // Get group date for movement tracking
        $stmt = $pdo->prepare("SELECT service_date FROM groups WHERE id = ?");
        $stmt->execute([$groupId]);
        $groupDate = $stmt->fetch()['service_date'];

        // Find singers being removed
        $removedSingers = array_diff($currentAssignments, $newSingerIds);
        foreach ($removedSingers as $singerId) {
            recordSingerMovement($singerId, $groupId, null, 'removed', $groupDate, 'Removed from group');
        }

        // Find singers being added
        $addedSingers = array_diff($newSingerIds, $currentAssignments);
        foreach ($addedSingers as $singerId) {
            // Check if they were in another group on the same date
            $stmt = $pdo->prepare("
                SELECT g.id as group_id, g.name as group_name
                FROM group_assignments ga
                JOIN groups g ON ga.group_id = g.id
                WHERE ga.singer_id = ? AND g.service_date = ? AND g.id != ?
            ");
            $stmt->execute([$singerId, $groupDate, $groupId]);
            $previousGroup = $stmt->fetch();

            $fromGroupId = $previousGroup ? $previousGroup['group_id'] : null;
            $movementType = $previousGroup ? 'transferred' : 'assigned';
            $notes = $previousGroup ? "Transferred from {$previousGroup['group_name']}" : 'Assigned to group';

            recordSingerMovement($singerId, $fromGroupId, $groupId, $movementType, $groupDate, $notes);
        }

    } catch (PDOException $e) {
        error_log("Failed to track assignment changes: " . $e->getMessage());
    }
}

function getGroupMovementHistory($groupId) {
    global $pdo;

    try {
        // Get group info
        $stmt = $pdo->prepare("SELECT * FROM groups WHERE id = ?");
        $stmt->execute([$groupId]);
        $group = $stmt->fetch();

        if (!$group) return null;

        // Use the same logic as the reports mixing tracking
        // Get all groups ordered by date to find movements involving this group
        $stmt = $pdo->query("
            SELECT g.id, g.name, g.service_date, COUNT(ga.singer_id) as singer_count
            FROM groups g
            LEFT JOIN group_assignments ga ON g.id = ga.group_id
            GROUP BY g.id, g.name, g.service_date
            ORDER BY g.service_date ASC, g.service_order ASC
        ");
        $allGroups = $stmt->fetchAll();

        // Group by date
        $groupsByDate = [];
        foreach ($allGroups as $g) {
            $groupsByDate[$g['service_date']][] = $g;
        }

        // Find movements involving this specific group
        $incomingMovements = [];
        $outgoingMovements = [];
        $previousAssignments = null;
        $previousDate = null;

        foreach ($groupsByDate as $date => $dateGroups) {
            if ($previousAssignments !== null && $previousDate !== null) {
                // Get current assignments for this date
                $currentAssignments = [];
                foreach ($dateGroups as $g) {
                    $stmt = $pdo->prepare("
                        SELECT ga.singer_id, s.full_name, s.voice_category, s.voice_level
                        FROM group_assignments ga
                        JOIN singers s ON ga.singer_id = s.id
                        WHERE ga.group_id = ?
                    ");
                    $stmt->execute([$g['id']]);
                    $currentAssignments[$g['name']] = $stmt->fetchAll();
                }

                // Check movements involving our target group
                $targetGroupInPrevious = isset($previousAssignments[$group['name']]);
                $targetGroupInCurrent = isset($currentAssignments[$group['name']]);

                if ($targetGroupInPrevious || $targetGroupInCurrent) {
                    // Analyze movements for this group
                    if ($targetGroupInPrevious) {
                        // Check singers who left this group
                        foreach ($previousAssignments[$group['name']] as $singer) {
                            $foundInCurrent = false;
                            if ($targetGroupInCurrent) {
                                // Check if singer stayed in this group
                                foreach ($currentAssignments[$group['name']] as $currentSinger) {
                                    if ($currentSinger['singer_id'] == $singer['singer_id']) {
                                        $foundInCurrent = true;
                                        break;
                                    }
                                }
                            }

                            if (!$foundInCurrent) {
                                // Singer left this group - find where they went
                                $foundNewGroup = false;
                                foreach ($currentAssignments as $currGroupName => $currSingers) {
                                    if ($currGroupName != $group['name']) {
                                        foreach ($currSingers as $currSinger) {
                                            if ($currSinger['singer_id'] == $singer['singer_id']) {
                                                // Singer moved to another group
                                                $outgoingMovements[] = [
                                                    'full_name' => $singer['full_name'],
                                                    'voice_category' => $singer['voice_category'],
                                                    'voice_level' => $singer['voice_level'],
                                                    'from_group_name' => $group['name'],
                                                    'to_group_name' => $currGroupName,
                                                    'movement_date' => $date,
                                                    'movement_type' => 'transferred',
                                                    'notes' => "Moved from {$group['name']} to {$currGroupName}"
                                                ];
                                                $foundNewGroup = true;
                                                break 2;
                                            }
                                        }
                                    }
                                }

                                if (!$foundNewGroup) {
                                    // Singer was removed (not in any current groups)
                                    $outgoingMovements[] = [
                                        'full_name' => $singer['full_name'],
                                        'voice_category' => $singer['voice_category'],
                                        'voice_level' => $singer['voice_level'],
                                        'from_group_name' => $group['name'],
                                        'to_group_name' => null,
                                        'movement_date' => $date,
                                        'movement_type' => 'removed',
                                        'notes' => "Removed from {$group['name']}"
                                    ];
                                }
                            }
                        }
                    }

                    if ($targetGroupInCurrent) {
                        // Check singers who joined this group
                        foreach ($currentAssignments[$group['name']] as $singer) {
                            $foundInPrevious = false;
                            if ($targetGroupInPrevious) {
                                // Check if singer was already in this group
                                foreach ($previousAssignments[$group['name']] as $prevSinger) {
                                    if ($prevSinger['singer_id'] == $singer['singer_id']) {
                                        $foundInPrevious = true;
                                        break;
                                    }
                                }
                            }

                            if (!$foundInPrevious) {
                                // Singer joined this group - find where they came from
                                $foundPreviousGroup = false;
                                foreach ($previousAssignments as $prevGroupName => $prevSingers) {
                                    if ($prevGroupName != $group['name']) {
                                        foreach ($prevSingers as $prevSinger) {
                                            if ($prevSinger['singer_id'] == $singer['singer_id']) {
                                                // Singer came from another group
                                                $incomingMovements[] = [
                                                    'full_name' => $singer['full_name'],
                                                    'voice_category' => $singer['voice_category'],
                                                    'voice_level' => $singer['voice_level'],
                                                    'from_group_name' => $prevGroupName,
                                                    'to_group_name' => $group['name'],
                                                    'movement_date' => $date,
                                                    'movement_type' => 'transferred',
                                                    'notes' => "Transferred from {$prevGroupName} to {$group['name']}"
                                                ];
                                                $foundPreviousGroup = true;
                                                break 2;
                                            }
                                        }
                                    }
                                }

                                if (!$foundPreviousGroup) {
                                    // Singer was newly assigned (not in any previous groups)
                                    $incomingMovements[] = [
                                        'full_name' => $singer['full_name'],
                                        'voice_category' => $singer['voice_category'],
                                        'voice_level' => $singer['voice_level'],
                                        'from_group_name' => null,
                                        'to_group_name' => $group['name'],
                                        'movement_date' => $date,
                                        'movement_type' => 'assigned',
                                        'notes' => "Initially assigned to {$group['name']}"
                                    ];
                                }
                            }
                        }
                    }
                }
            }

            // Prepare assignments for next iteration
            $previousAssignments = [];
            foreach ($dateGroups as $g) {
                $stmt = $pdo->prepare("
                    SELECT ga.singer_id, s.full_name, s.voice_category, s.voice_level
                    FROM group_assignments ga
                    JOIN singers s ON ga.singer_id = s.id
                    WHERE ga.group_id = ?
                ");
                $stmt->execute([$g['id']]);
                $previousAssignments[$g['name']] = $stmt->fetchAll();
            }
            $previousDate = $date;
        }

        return [
            'group' => $group,
            'incoming' => $incomingMovements,
            'outgoing' => $outgoingMovements
        ];

    } catch (PDOException $e) {
        error_log("Failed to get group movement history: " . $e->getMessage());
        return null;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Groups - Reverence Worship Team</title>
    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="../css/admin.css">
    <link rel="stylesheet" href="../css/groups.css">
</head>
<body>
    <div class="admin-layout">
        <div class="admin-sidebar">
            <div class="logo-container">
                <img src="../assets/Logo Reverence-Photoroom.png" alt="Reverence WorshipTeam Logo" class="logo">
            </div>
            <div class="sidebar-title">
                <h2>Admin Panel</h2>
            </div>
            <nav>
                <a href="dashboard.php">Dashboard</a>
                <a href="singers.php">Manage Singers</a>
                <a href="groups.php" class="active">Manage Groups</a>
                <a href="reports.php">Reports</a>
                <a href="images.php">Manage Images</a>
                <a href="settings.php">Settings</a>
                <a href="../logout.php">Logout</a>
            </nav>
        </div>

        <div class="admin-main">
            <div class="admin-header">
                <h1>Manage Groups</h1>
            </div>

            <!-- Horizontal Navigation -->
            <div class="horizontal-nav">
                <a href="groups.php" class="nav-tab <?php echo $action === 'list' ? 'active' : ''; ?>">
                     Published Groups
                </a>
                <a href="groups.php?action=history" class="nav-tab <?php echo $action === 'history' ? 'active' : ''; ?>">
                     Group History
                </a>
                <a href="groups.php?action=create" class="nav-tab <?php echo $action === 'create' ? 'active' : ''; ?>">
                    ➕ Create Groups
                </a>
            </div>

            <div class="admin-content">
                <?php
                // Display messages and errors
                if (!empty($errors)): ?>
                    <div class="message error">
                        <strong>Errors occurred:</strong>
                        <ul>
                            <?php foreach ($errors as $error): ?>
                                <li><?php echo htmlspecialchars($error); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <?php if (!empty($warnings)): ?>
                    <div class="message warning">
                        <strong>Warnings:</strong>
                        <ul>
                            <?php foreach ($warnings as $warning): ?>
                                <li><?php echo htmlspecialchars($warning); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <?php if ($message): ?>
                    <div class="message <?php echo strpos($message, 'Error') === 0 || strpos($message, '❌') !== false ? 'error' : 'success'; ?>">
                        <?php echo $message; ?>
                    </div>
                <?php endif; ?>

                <?php if ($action === 'create'): ?>
                    <div class="form-container">
                        <h3>Create New Groups</h3>
                        <form method="POST" id="create-groups-form">
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="service_date">Service Date:</label>
                                    <input type="date" id="service_date" name="service_date" value="<?php echo date('Y-m-d'); ?>" required>
                                </div>
                                <div class="form-group">
                                    <label for="group_count">Number of Groups:</label>
                                    <input type="number" id="group_count" name="group_count" min="1" max="10" value="1" required>
                                    <small>Number of groups on this date (1-10)</small>
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="form-group">
                                    <label for="mixing_method">Group Creation Method:</label>
                                    <select id="mixing_method" name="mixing_method" required>
                                        <option value="random">🎲 Random Assignment</option>
                                        <option value="balanced">⚖️ Balanced Distribution</option>
                                        <option value="manual">👥 Manual Assignment</option>
                                    </select>
                                    <small>Choose how singers are assigned to groups</small>
                                </div>
                            </div>

                            <div id="method-description" class="method-description">
                                <div class="method-info" data-method="balanced">
                                    <strong>⚖️ Balanced Distribution:</strong> Distributes singers evenly by voice type and skill level. Maintains musical balance.
                                </div>
                                <div class="method-info" data-method="random">
                                    <strong>🎲 Random Assignment:</strong> Completely random distribution. Perfect for creating varied group compositions.
                                </div>
                                <div class="method-info" data-method="manual">
                                    <strong>👥 Manual Assignment:</strong> Create empty groups that you can manually assign singers to later.
                                </div>
                            </div>

                            <div id="group_names_container">
                                <!-- Dynamic group name inputs will be added here -->
                            </div>

                            <div class="form-actions">
                                <button type="submit" name="create_groups" class="btn">Create Groups</button>
                                <a href="groups.php" class="btn">Cancel</a>
                            </div>
                        </form>
                    </div>

                <?php elseif ($action === 'edit' && $editGroup): ?>
                    <div class="form-container">
                        <h3>Edit Group: <?php echo htmlspecialchars($editGroup['name']); ?></h3>
                        <form method="POST">
                            <input type="hidden" name="group_id" value="<?php echo $editGroup['id']; ?>">
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="group_name">Group Name:</label>
                                    <input type="text" id="group_name" name="group_name" value="<?php echo htmlspecialchars($editGroup['name']); ?>" required>
                                </div>
                                <div class="form-group">
                                    <label for="service_date">Service Date:</label>
                                    <input type="date" id="service_date" name="service_date" value="<?php echo $editGroup['service_date'] ?? date('Y-m-d', strtotime($editGroup['created_at'])); ?>" required>
                                </div>
                            </div>
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="service_order">Service Order:</label>
                                    <input type="number" id="service_order" name="service_order" min="1" value="<?php echo $editGroup['service_order'] ?? 1; ?>" required>
                                    <small>Order of this service for the day</small>
                                </div>
                                <div class="form-group">
                                    <label for="is_published">Publish Status:</label>
                                    <div style="margin-top: 0.5rem;">
                                        <input type="checkbox" id="is_published" name="is_published" <?php echo $editGroup['is_published'] ? 'checked' : ''; ?>>
                                        <label for="is_published" style="display: inline; margin-left: 0.5rem;">Publish this group</label>
                                    </div>
                                </div>
                            </div>
                            <div class="form-actions">
                                <button type="submit" name="update_group" class="btn">Update Group</button>
                                <a href="groups.php?action=history" class="btn">Cancel</a>
                            </div>
                        </form>
                    </div>

                <?php elseif ($action === 'view' && $viewGroup): ?>
                    <div class="group-view">
                        <div class="group-header">
                            <h3><?php echo htmlspecialchars($viewGroup['name']); ?></h3>
                            <div class="group-meta">
                                Service Date: <?php echo date('M j, Y', strtotime($viewGroup['service_date'] ?? $viewGroup['created_at'])); ?> •
                                Status: <span class="status-<?php echo $viewGroup['is_published'] ? 'published' : 'draft'; ?>">
                                    <?php echo $viewGroup['is_published'] ? 'Published' : 'Draft'; ?>
                                </span>
                            </div>
                        </div>

                        <div class="voice-breakdown">
                            <?php
                            $stmt = $pdo->prepare("
                                SELECT s.* FROM singers s
                                JOIN group_assignments ga ON s.id = ga.singer_id
                                WHERE ga.group_id = ?
                                ORDER BY s.voice_category, s.voice_level DESC, s.full_name
                            ");
                            $stmt->execute([$viewGroup['id']]);
                            $groupSingers = $stmt->fetchAll();

                            // Get previous group information for each singer and check if they stayed in same group
                            $singerPreviousGroups = [];
                            $singerStayedSameGroup = [];
                            foreach ($groupSingers as $singer) {
                                // Get all historical group assignments for this singer
                                $stmt = $pdo->prepare("
                                    SELECT g.name as group_name
                                    FROM groups g
                                    JOIN group_assignments ga ON g.id = ga.group_id
                                    WHERE ga.singer_id = ?
                                    ORDER BY g.service_date ASC
                                ");
                                $stmt->execute([$singer['id']]);
                                $allAssignments = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);

                                // Check if singer has been in the same group for all assignments
                                $uniqueGroups = array_unique($allAssignments);
                                $hasStayedSameGroup = count($uniqueGroups) === 1 && count($allAssignments) > 1;

                                // Get most recent previous group
                                $previousGroup = null;
                                if (count($allAssignments) > 1) {
                                    // Find the most recent assignment before current group
                                    $stmt = $pdo->prepare("
                                        SELECT g.name as group_name
                                        FROM groups g
                                        JOIN group_assignments ga ON g.id = ga.group_id
                                        WHERE ga.singer_id = ? AND g.service_date < ?
                                        ORDER BY g.service_date DESC
                                        LIMIT 1
                                    ");
                                    $stmt->execute([$singer['id'], $viewGroup['service_date']]);
                                    $prevResult = $stmt->fetch();
                                    $previousGroup = $prevResult ? $prevResult['group_name'] : null;
                                }

                                $singerPreviousGroups[$singer['id']] = $previousGroup;
                                $singerStayedSameGroup[$singer['id']] = $hasStayedSameGroup;
                            }

                            $voiceData = [];
                            foreach ($groupSingers as $singer) {
                                $voiceData[$singer['voice_category']][] = $singer;
                            }

                            $voices = ['Soprano', 'Alto', 'Tenor', 'Bass'];
                            foreach ($voices as $voice):
                                $singers = $voiceData[$voice] ?? [];
                                $goodCount = count(array_filter($singers, fn($s) => $s['voice_level'] === 'Good'));
                                $normalCount = count(array_filter($singers, fn($s) => $s['voice_level'] === 'Normal'));
                            ?>
                                <div class="voice-category">
                                    <h5><?php echo $voice; ?> <span class="count">(<?php echo count($singers); ?>)</span></h5>
                        <?php if (!empty($singers)): ?>
                                        <div class="singer-list">
                                            <?php $singerCounter = 1; ?>
                                            <?php foreach ($singers as $singer):
                                                $previousGroup = $singerPreviousGroups[$singer['id']];
                                                $hasStayedSameGroup = $singerStayedSameGroup[$singer['id']] ?? false;
                                                $previousText = $previousGroup ? " - " . ($hasStayedSameGroup ? "<strong style='color: #dc3545;'>Previously: {$previousGroup}</strong>" : "Previously: {$previousGroup}") : "";
                                            ?>
                                                <div class="singer-item <?php echo strtolower($singer['voice_level']); ?>">
                                                    <span class="singer-name"><?php echo $singerCounter; ?>. <?php echo htmlspecialchars($singer['full_name']); ?> (<?php echo $singer['voice_level']; ?>)<?php echo $previousText; ?></span>
                                                </div>
                                            <?php $singerCounter++; ?>
                                            <?php endforeach; ?>
                                        </div>
                                        <div class="voice-stats">
                                            <span class="stat good">Good: <?php echo $goodCount; ?></span>
                                            <span class="stat normal">Normal: <?php echo $normalCount; ?></span>
                                        </div>
                                    <?php else: ?>
                                        <p class="no-singers">No singers assigned</p>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <div class="group-actions">
                            <a href="groups.php?action=assign&id=<?php echo $viewGroup['id']; ?>" class="btn btn-primary">👥 Manage Singers</a>
                            <a href="groups.php?action=movements&id=<?php echo $viewGroup['id']; ?>" class="btn btn-secondary">📊 See Movement</a>
                            <a href="groups.php?action=history" class="btn">Back to Groups</a>
                        </div>
                    </div>

                <?php elseif ($action === 'assign' && $assignGroup): ?>
                    <div class="assignment-interface">
                        <div class="assignment-header">
                            <h3>Manage Singers: <?php echo htmlspecialchars($assignGroup['name']); ?></h3>
                            <div class="group-meta">
                                Service Date: <?php echo date('M j, Y', strtotime($assignGroup['service_date'] ?? $assignGroup['created_at'])); ?> •
                                Status: <span class="status-<?php echo $assignGroup['is_published'] ? 'published' : 'draft'; ?>">
                                    <?php echo $assignGroup['is_published'] ? 'Published' : 'Draft'; ?>
                                </span>
                            </div>
                        </div>

                        <form method="POST">
                            <input type="hidden" name="group_id" value="<?php echo $assignGroup['id']; ?>">

                            <div class="assignment-container">
                                <div class="available-singers">
                                    <h4>Available Singers</h4>
                                    <p class="assignment-note">⚠️ <strong>Note:</strong> Singers already assigned to other groups on this date are disabled.</p>
                                    <div class="singer-selection">
                                        <?php
                                        $allSingers = getActiveSingers();
                                        $assignedSingers = [];
                                        $stmt = $pdo->prepare("SELECT singer_id FROM group_assignments WHERE group_id = ?");
                                        $stmt->execute([$assignGroup['id']]);
                                        $assignedIds = array_column($stmt->fetchAll(), 'singer_id');

                                        // Get singers already assigned to other groups on the same date
                                        $unavailableSingers = [];
                                        $stmt = $pdo->prepare("
                                            SELECT DISTINCT ga.singer_id
                                            FROM group_assignments ga
                                            JOIN groups g ON ga.group_id = g.id
                                            WHERE g.service_date = ? AND g.id != ?
                                        ");
                                        $stmt->execute([$assignGroup['service_date'], $assignGroup['id']]);
                                        $unavailableIds = array_column($stmt->fetchAll(), 'singer_id');
                                        $unavailableSingers = array_flip($unavailableIds);

                                        $voices = ['Soprano', 'Alto', 'Tenor', 'Bass'];
                                        foreach ($voices as $voice):
                                            $voiceSingers = array_filter($allSingers, fn($s) => $s['voice_category'] === $voice);
                                            if (empty($voiceSingers)) continue;

                                            $availableCount = count(array_filter($voiceSingers, fn($s) => !isset($unavailableSingers[$s['id']]) || in_array($s['id'], $assignedIds)));
                                        ?>
                                            <div class="voice-section">
                                                <h5><?php echo $voice; ?>s <span class="count">(<?php echo $availableCount; ?>/<?php echo count($voiceSingers); ?> available)</span></h5>
                                                <div class="singer-list">
                                                    <?php foreach ($voiceSingers as $singer):
                                                        $isUnavailable = isset($unavailableSingers[$singer['id']]) && !in_array($singer['id'], $assignedIds);
                                                        $isChecked = in_array($singer['id'], $assignedIds);
                                                    ?>
                                                        <label class="singer-checkbox <?php echo $isUnavailable ? 'unavailable' : ''; ?>">
                                                            <input type="checkbox" name="singers[]" value="<?php echo $singer['id']; ?>"
                                                                   <?php echo $isChecked ? 'checked' : ''; ?>
                                                                   <?php echo $isUnavailable ? 'disabled' : ''; ?>>
                                                            <span>
                                                                <?php echo htmlspecialchars($singer['full_name']); ?>
                                                                <small>(<?php echo $singer['voice_level']; ?>)</small>
                                                                <?php if ($isUnavailable): ?>
                                                                    <em class="unavailable-text"> - Assigned to another group</em>
                                                                <?php endif; ?>
                                                            </span>
                                                        </label>
                                                    <?php endforeach; ?>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>

                                <div class="assignment-summary">
                                    <h4>Current Assignment</h4>
                                    <div class="summary-stats">
                                        <p><strong>Selected Singers:</strong> <span id="selected-count"><?php echo count($assignedIds); ?></span></p>
                                        <?php
                                        $voiceBreakdown = [];
                                        foreach ($assignedIds as $singerId) {
                                            $stmt = $pdo->prepare("SELECT voice_category FROM singers WHERE id = ?");
                                            $stmt->execute([$singerId]);
                                            $voice = $stmt->fetch()['voice_category'];
                                            $voiceBreakdown[$voice] = ($voiceBreakdown[$voice] ?? 0) + 1;
                                        }
                                        foreach ($voices as $voice): ?>
                                            <p><?php echo $voice; ?>: <span class="voice-count"><?php echo $voiceBreakdown[$voice] ?? 0; ?></span></p>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>

                            <div class="form-actions">
                                <button type="submit" name="update_assignments" class="btn btn-primary">💾 Save Assignments</button>
                                <a href="groups.php?action=view&id=<?php echo $assignGroup['id']; ?>" class="btn">View Group</a>
                                <a href="groups.php?action=history" class="btn">Back to Groups</a>
                            </div>
                        </form>
                    </div>

                <?php elseif ($action === 'history'): ?>
                    <div class="groups-history">
                        <h3>Group History</h3>
                        <div class="table-container">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Group Name</th>
                                        <th>Date</th>
                                        <th>Singers</th>
                                        <th>Creator</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php $historyCounter = 1; ?>
                                    <?php foreach ($allGroups as $group):
                                        // Count singers in this group
                                        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM group_assignments WHERE group_id = ?");
                                        $stmt->execute([$group['id']]);
                                        $singerCount = $stmt->fetch()['count'];
                                    ?>
                                        <tr>
                                            <td><?php echo $historyCounter++; ?>. <?php echo htmlspecialchars($group['name']); ?></td>
                                            <td><?php echo date('M j, Y', strtotime($group['service_date'] ?? $group['created_at'])); ?></td>
                                            <td><?php echo $singerCount; ?> singers</td>
                                            <td><?php echo htmlspecialchars($group['creator'] ?: 'System'); ?></td>
                                            <td>
                                                <span class="status-badge status-<?php echo $group['is_published'] ? 'published' : 'draft'; ?>">
                                                    <?php echo $group['is_published'] ? 'Published' : 'Draft'; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <a href="groups.php?action=view&id=<?php echo $group['id']; ?>" class="btn btn-sm">View</a>
                                            <a href="groups.php?action=edit&id=<?php echo $group['id']; ?>" class="btn btn-sm">Edit</a>
                                            <?php if (!$group['is_published']): ?>
                                                <form method="POST" style="display: inline;">
                                                    <input type="hidden" name="group_id" value="<?php echo $group['id']; ?>">
                                                    <button type="submit" name="publish_groups" class="btn btn-sm">Publish</button>
                                                </form>
                                            <?php endif; ?>
                                            <form method="POST" style="display: inline;">
                                                <input type="hidden" name="group_id" value="<?php echo $group['id']; ?>">
                                                <button type="submit" name="delete_groups" class="btn btn-sm btn-danger" onclick="return confirm('Are you sure you want to delete this group?')">Delete</button>
                                            </form>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                <?php elseif ($action === 'movements' && $movementHistory): ?>
                    <div class="movement-history">
                        <div class="movement-header">
                            <h3>Movement History: <?php echo htmlspecialchars($movementHistory['group']['name']); ?></h3>
                            <div class="group-meta">
                                Service Date: <?php echo date('M j, Y', strtotime($movementHistory['group']['service_date'])); ?> •
                                Status: <span class="status-<?php echo $movementHistory['group']['is_published'] ? 'published' : 'draft'; ?>">
                                    <?php echo $movementHistory['group']['is_published'] ? 'Published' : 'Draft'; ?>
                                </span>
                            </div>
                        </div>

                        <div class="movement-container">
                            <!-- Singers who moved INTO this group -->
                            <div class="movement-section incoming">
                                <h4>📥 Singers Added to This Group <span class="count">(<?php echo count($movementHistory['incoming']); ?>)</span></h4>
                                <?php if (!empty($movementHistory['incoming'])): ?>
                                    <div class="movement-list">
                                        <?php foreach ($movementHistory['incoming'] as $movement): ?>
                                            <div class="movement-item <?php echo strtolower($movement['voice_level']); ?>">
                                                <div class="singer-info">
                                                    <strong><?php echo htmlspecialchars($movement['full_name']); ?></strong>
                                                    <span class="voice-type"><?php echo $movement['voice_category']; ?> (<?php echo $movement['voice_level']; ?>)</span>
                                                </div>
                                                <div class="movement-details">
                                                    <span class="movement-type <?php echo $movement['movement_type']; ?>">
                                                        <?php
                                                        if ($movement['movement_type'] === 'assigned') {
                                                            echo '🎯 Initially Assigned';
                                                        } elseif ($movement['movement_type'] === 'transferred') {
                                                            echo '🔄 Transferred from: ' . htmlspecialchars($movement['from_group_name'] ?: 'Unknown');
                                                        } else {
                                                            echo ucfirst($movement['movement_type']);
                                                        }
                                                        ?>
                                                    </span>
                                                    <span class="movement-date"><?php echo date('M j, Y', strtotime($movement['movement_date'])); ?></span>
                                                </div>
                                                <?php if ($movement['notes']): ?>
                                                    <div class="movement-notes"><?php echo htmlspecialchars($movement['notes']); ?></div>
                                                <?php endif; ?>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <p class="no-movements">No singers have been added to this group.</p>
                                <?php endif; ?>
                            </div>

                            <!-- Singers who moved OUT of this group -->
                            <div class="movement-section outgoing">
                                <h4>📤 Singers Removed from This Group <span class="count">(<?php echo count($movementHistory['outgoing']); ?>)</span></h4>
                                <?php if (!empty($movementHistory['outgoing'])): ?>
                                    <div class="movement-list">
                                        <?php foreach ($movementHistory['outgoing'] as $movement): ?>
                                            <div class="movement-item <?php echo strtolower($movement['voice_level']); ?>">
                                                <div class="singer-info">
                                                    <strong><?php echo htmlspecialchars($movement['full_name']); ?></strong>
                                                    <span class="voice-type"><?php echo $movement['voice_category']; ?> (<?php echo $movement['voice_level']; ?>)</span>
                                                </div>
                                                <div class="movement-details">
                                                    <span class="movement-type removed">
                                                        ❌ Removed from group
                                                    </span>
                                                    <span class="movement-date"><?php echo date('M j, Y', strtotime($movement['movement_date'])); ?></span>
                                                </div>
                                                <?php if ($movement['notes']): ?>
                                                    <div class="movement-notes"><?php echo htmlspecialchars($movement['notes']); ?></div>
                                                <?php endif; ?>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <p class="no-movements">No singers have been removed from this group.</p>
                                <?php endif; ?>
                            </div>
                        </div>

                       

                        <div class="movement-actions">
                            <a href="groups.php?action=view&id=<?php echo $movementHistory['group']['id']; ?>" class="btn">View Group</a>
                            <a href="groups.php?action=assign&id=<?php echo $movementHistory['group']['id']; ?>" class="btn btn-primary">Manage Singers</a>
                            <a href="groups.php?action=history" class="btn">Back to Groups</a>
                        </div>
                    </div>

                <?php else: ?>
                    <div class="published-groups">
                        <div class="groups-header">
                            <h3>Published Groups</h3>
                           
                        </div>

                        <?php if (empty($publishedGroups)): ?>
                            <div class="no-groups">
                                <p>No published groups found.</p>
                                <?php if (empty($allGroups)): ?>
                                    <a href="groups.php?action=create" class="btn">Create Your First Groups</a>
                                <?php else: ?>
                                    <a href="groups.php?action=history" class="btn">Publish Your Groups</a>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <div class="groups-grid">
                                <?php $groupCounter = 1; ?>
                                <?php foreach ($publishedGroups as $group):
                                    // Count singers in this group
                                    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM group_assignments WHERE group_id = ?");
                                    $stmt->execute([$group['id']]);
                                    $singerCount = $stmt->fetch()['count'];
                                ?>
                                    <div class="group-card">
                                        <div class="group-card-header">
                                            <h4><?php echo $groupCounter; ?>. <?php echo htmlspecialchars($group['name']); ?></h4>
                                          
                                        </div>
                                        <div class="group-card-meta">
                                            <span class="service-date"><?php echo date('M j, Y', strtotime($group['service_date'])); ?></span>
                                            <span class="singer-count"><?php echo $singerCount; ?> singers</span>
                                        </div>
                                    <div class="group-card-actions">
                                            <a href="groups.php?action=view&id=<?php echo $group['id']; ?>" class="btn btn-sm">View Details</a>
                                            <a href="groups.php?action=assign&id=<?php echo $group['id']; ?>" class="btn btn-sm btn-secondary">Manage</a>
                                            <form method="POST" style="display: inline;">
                                                <input type="hidden" name="group_id" value="<?php echo $group['id']; ?>">
                                                <button type="submit" name="publish_groups" class="btn btn-sm btn-warning" onclick="return confirm('Are you sure you want to unpublish this group?')">Unpublish</button>
                                            </form>
                                        </div>
                                    </div>
                                <?php $groupCounter++; ?>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

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
                <h4>Group Actions</h4>
                <p><a href="groups.php?action=create">Create New Groups</a></p>
                <p><a href="singers.php">Manage Singers</a></p>
                <p><a href="images.php">Upload Images</a></p>
                <p><a href="dashboard.php">← Back to Dashboard</a></p>
            </div>

            
        </div>

        <div class="footer-bottom">
            <div class="copyright">
                <p>&copy; 2026 Reverence WorshipTeam. All rights reserved.</p>
                
            </div>
        </div>
    </footer>

    <script src="../js/main.js"></script>
    <script>
        // Dynamic group name inputs (only for create page)
        const groupCountElement = document.getElementById('group_count');
        if (groupCountElement) {
            groupCountElement.addEventListener('change', function() {
                const count = parseInt(this.value) || 0;
                const container = document.getElementById('group_names_container');

                // Clear existing inputs
                if (container) {
                    container.innerHTML = '';

                    // Add new inputs
                    for (let i = 1; i <= count; i++) {
                        const inputGroup = document.createElement('div');
                        inputGroup.className = 'form-group';
                        inputGroup.innerHTML = `
                            <label for="group_name_${i}">Group ${i} Name:</label>
                            <input type="text" id="group_name_${i}" name="group_names[]" value="Service ${i}" required>
                        `;
                        container.appendChild(inputGroup);
                    }
                }
            });

            // Trigger change event on page load to show initial inputs
            groupCountElement.dispatchEvent(new Event('change'));
        }

        // Method description toggle (only for create page)
        const mixingMethodElement = document.getElementById('mixing_method');
        if (mixingMethodElement) {
            mixingMethodElement.addEventListener('change', function() {
                const selectedMethod = this.value;
                const descriptions = document.querySelectorAll('.method-info');

                // Hide all descriptions
                descriptions.forEach(desc => desc.style.display = 'none');

                // Show selected method description
                const selectedDesc = document.querySelector(`.method-info[data-method="${selectedMethod}"]`);
                if (selectedDesc) {
                    selectedDesc.style.display = 'block';
                }
            });

            // Trigger change event on page load to show default method
            mixingMethodElement.dispatchEvent(new Event('change'));
        }

        // Update selected singer count dynamically (only for assign page)
        const checkboxes = document.querySelectorAll('input[name="singers[]"]');
        const selectedCount = document.getElementById('selected-count');

        if (checkboxes.length > 0) {
            function updateSelectedCount() {
                const checkedBoxes = document.querySelectorAll('input[name="singers[]"]:checked');
                if (selectedCount) {
                    selectedCount.textContent = checkedBoxes.length;
                }

                // Update voice counts
                const voiceCounts = { 'Soprano': 0, 'Alto': 0, 'Tenor': 0, 'Bass': 0 };
                checkedBoxes.forEach(checkbox => {
                    const label = checkbox.closest('label');
                    const span = label.querySelector('span');
                    if (span) {
                        const text = span.textContent;
                        // Extract voice category from the text
                        if (text.includes('(Good)') || text.includes('(Normal)')) {
                            // Find the parent voice section
                            const voiceSection = checkbox.closest('.voice-section');
                            if (voiceSection) {
                                const h5 = voiceSection.querySelector('h5');
                                if (h5) {
                                    const voiceType = h5.textContent.replace(/s\s*\(.*/, ''); // Remove 's' and count
                                    voiceCounts[voiceType] = (voiceCounts[voiceType] || 0) + 1;
                                }
                            }
                        }
                    }
                });

                // Update voice count displays
                Object.keys(voiceCounts).forEach(voice => {
                    const countElement = document.querySelector(`.voice-count[data-voice="${voice}"]`);
                    if (countElement) {
                        countElement.textContent = voiceCounts[voice];
                    }
                });
            }

            checkboxes.forEach(checkbox => {
                checkbox.addEventListener('change', updateSelectedCount);
            });

            // Initial count
            updateSelectedCount();
        }
    </script>
</body>
</html>
