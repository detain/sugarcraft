<?php

declare(strict_types=1);

namespace SugarCraft\Input;

use SugarCraft\Input\Event\KeyEvent;
use SugarCraft\Input\Event\MouseEvent;
use SugarCraft\Input\Event\FocusEvent;
use SugarCraft\Input\Event\PasteEvent;
use SugarCraft\Input\Event\ResizeEvent;

/**
 * Reads terminal input events from a PHP resource.
 *
 * @see Mirrors charmbracelet/bubbletea (input handling).
 */
interface InputDriver
{
    /**
     * Read and return the next input event, or null on EOF or when
     * non-blocking mode finds no data.
     *
     * @return Event|KeyEvent|MouseEvent|FocusEvent|PasteEvent|ResizeEvent|null
     */
    public function read(): Event|null;
}
