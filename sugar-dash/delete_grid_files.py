import os
files = [
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
]
for f in files:
    if os.path.exists(f):
        os.unlink(f)
        print(f"Deleted: {f}")
print("Done")
