<?php
try {
    // جرب مسارين مختلفين لملف SQLite
    $db_path1 = __DIR__ . '/database/data.sqlite';
    $db_path2 = __DIR__ . '/../database/data.sqlite';
    
    if (file_exists($db_path1)) {
        $db = new PDO("sqlite:" . $db_path1);
    } elseif (file_exists($db_path2)) {
        $db = new PDO("sqlite:" . $db_path2);
    } else {
        // إذا ما فيش قاعدة بيانات، استمر بدونها
        $db = null;
    }
    
    if ($db) {
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }
} catch (PDOException $e) {
    // تجاهل الخطأ واستمر
    $db = null;
}
?>
