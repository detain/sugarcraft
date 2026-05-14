<?php
$filesToDelete = [
    '/home/sites/sugarcraft/sugar-dash/src/Grid/Event.php',
    '/home/sites/sugarcraft/sugar-dash/src/Grid/EventDispatcher.php',
    '/home/sites/sugarcraft/sugar-dash/src/Grid/EventHandler.php',
    '/home/sites/sugarcraft/sugar-dash/src/Grid/FocusEvent.php',
    '/home/sites/sugarcraft/sugar-dash/src/Grid/KeyEvent.php',
    '/home/sites/sugarcraft/sugar-dash/src/Grid/MouseEvent.php',
    '/home/sites/sugarcraft/sugar-dash/src/Grid/PasteEvent.php',
    '/home/sites/sugarcraft/sugar-dash/src/Grid/ResizeEvent.php',
    '/home/sites/sugarcraft/sugar-dash/src/Grid/Key.php',
    '/home/sites/sugarcraft/sugar-dash/src/Grid/KeyAction.php',
    '/home/sites/sugarcraft/sugar-dash/src/Grid/KeyMap.php',
    '/home/sites/sugarcraft/sugar-dash/src/Grid/Focus.php',
    '/home/sites/sugarcraft/sugar-dash/src/Grid/State.php',
];

foreach ($filesToDelete as $file) {
    if (file_exists($file)) {
        unlink($file);
        echo "Deleted: $file\n";
    }
}
echo "Done\n";
