<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return !$user->is_suspended && (int) $user->id === (int) $id;
});
