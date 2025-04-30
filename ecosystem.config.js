export const apps = [
    {
        name: 'api-laravel',
        script: 'php',
        args: 'artisan serve --host=0.0.0.0 --port=8000',
        interpreter: 'none',
        cwd: 'C:/laragon/www/server-ws-laravel',
    },
    {
        name: 'reverb',
        script: 'php',
        args: 'artisan reverb:start',
        interpreter: 'none',
        cwd: 'C:/laragon/www/server-ws-laravel',
    },
    {
        name: 'queue-default',
        script: 'php',
        args: 'artisan queue:work --queue=default',
        interpreter: 'none',
        cwd: 'C:/laragon/www/server-ws-laravel',
    },
    {
        name: 'queue-python',
        script: 'php',
        args: 'artisan queue:work --queue=python',
        interpreter: 'none',
        cwd: 'C:/laragon/www/server-ws-laravel',
    },
    {
        name: 'queue-python2',
        script: 'php',
        args: 'artisan queue:work --queue=python2',
        interpreter: 'none',
        cwd: 'C:/laragon/www/server-ws-laravel',
    },
];
  