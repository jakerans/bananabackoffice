<?php

/*
 * Only what differs from Livewire's own settings; the rest are its defaults.
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Temporary File Uploads
    |--------------------------------------------------------------------------
    |
    | A file chosen in a form is held here until it is saved. Livewire's own
    | limit is 12 MB; Backoffice accepts up to 20 MB (decision 157, question
    | 5). The file's type is checked when it is saved (App\Actions\Attachments).
    | Held files stay on the server's own disk, never in the files bucket, and
    | are cleared after a day.
    |
    */

    'temporary_file_upload' => [
        'disk' => env('LIVEWIRE_TEMPORARY_FILE_UPLOAD_DISK'),
        'rules' => ['required', 'file', 'max:20480'],
        'directory' => null,
        'middleware' => null,
        'preview_mimes' => [
            'png', 'gif', 'bmp', 'svg', 'wav', 'mp4',
            'mov', 'avi', 'wmv', 'mp3', 'm4a',
            'jpg', 'jpeg', 'mpga', 'webp', 'wma',
        ],
        'max_upload_time' => 5,
        'cleanup' => true,
    ],

];
