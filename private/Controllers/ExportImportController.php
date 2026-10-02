<?php

namespace Pterodactyl\BlueprintFramework\Extensions\{identifier}\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Pterodactyl\Http\Controllers\Controller;
use Pterodactyl\BlueprintFramework\Extensions\{identifier}\Services\ThemePresetManager;

/**
 * Export / import theme presets as JSON files.
 */
class ExportImportController extends Controller
{
    public function __construct(private ThemePresetManager $presets)
    {
    }

    /** Download all presets (current custom config if none saved). */
    public function export(): Response
    {
        $payload = $this->presets->exportAll();

        return response((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), 200)
            ->header('Content-Type', 'application/json')
            ->header('Content-Disposition', 'attachment; filename="primus-presets.json"');
    }

    /** Upload a previously exported presets file. */
    public function import(Request $request): JsonResponse
    {
        $file = $request->file('file');

        if ($file === null || !$file->isValid()) {
            return response()->json(['error' => 'No valid file was uploaded.'], 422);
        }
        if ($file->getSize() > 1024 * 256) {
            return response()->json(['error' => 'Preset file too large (max 256 KB).'], 422);
        }

        $decoded = json_decode((string) $file->get(), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return response()->json(['error' => 'File is not valid JSON.'], 422);
        }

        if (($decoded['type'] ?? '') !== 'primus.presets' && !isset($decoded['presets'])) {
            return response()->json(['error' => 'File does not look like a Primus presets export.'], 422);
        }

        $count = $this->presets->importAll($decoded);

        return $count > 0
            ? response()->json(['imported' => $count])
            : response()->json(['error' => 'No usable presets found in the file.'], 422);
    }
}
