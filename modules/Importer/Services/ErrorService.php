<?php

namespace Modules\Importer\Services;

use Illuminate\Support\MessageBag;

class ErrorService
{
    /**
     * Push an error into the errors session bag.
     *
     * @param string $key
     * @param string $message
     * @return void
     */
    public function push(string $key, string $message): void
    {
        // Get current errors from session
        $errors = session()->get('errors', new MessageBag);

        if (!$errors instanceof MessageBag) {
            $errors = new MessageBag((array)$errors);
        }

        // Add error
        $errors->add($key, $message);

        // Flash back into session
        session()->flash('errors', $errors);
    }

    /**
     * Get all errors from the session.
     */
    public function all(): MessageBag
    {
        $errors = session()->get('errors', new MessageBag);

        return $errors instanceof MessageBag
            ? $errors
            : new MessageBag((array)$errors);
    }
}
