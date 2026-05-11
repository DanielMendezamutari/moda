<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Copia el build del frontend (Vite modo hosting) a public/panel/ para un solo despliegue con Laravel.
 */
class PublishPanelCommand extends Command
{
    protected $signature = 'panel:publish {--source= : Ruta absoluta al build (por defecto ../frontend/compilacion-para-hosting)}';

    protected $description = 'Copia el panel Vue compilado a public/panel/ (ejecutar tras npm run build:hosting en frontend/)';

    public function handle(): int
    {
        $source = $this->option('source')
            ?: realpath(base_path('../frontend/compilacion-para-hosting'));

        if ($source === false || ! is_dir($source)) {
            $this->error('No se encuentra el build del frontend.');
            $this->line('En tu PC: cd frontend && npm run build:hosting  (o pnpm run build:hosting)');
            $this->line('Debe existir la carpeta frontend/compilacion-para-hosting junto a backend/.');

            return self::FAILURE;
        }

        $target = public_path('panel');

        if (File::isDirectory($target))
            File::deleteDirectory($target);

        File::ensureDirectoryExists($target);
        File::copyDirectory($source, $target);

        foreach (['.htaccess', 'index.php'] as $noise) {
            $f = $target.DIRECTORY_SEPARATOR.$noise;
            if (File::exists($f)) {
                File::delete($f);
                $this->line("Quitado panel/{$noise} (las rutas SPA las define public/.htaccess de Laravel).");
            }
        }

        $this->info('Panel publicado en '.str_replace(base_path(), '.', $target));
        $this->line('Abrí en el navegador la URL base de tu app + /panel/ (según APP_URL), por ejemplo: '.url('/panel/'));

        return self::SUCCESS;
    }
}
