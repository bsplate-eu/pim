<?php

namespace App\Http\Controllers\Admin\Translations;

use App\Http\Requests\Admin\Translation\ExportTranslations;
use App\Http\Requests\Admin\Translation\ImportTranslation;
use App\Http\Requests\Admin\Translation\IndexTranslation;
use App\Http\Requests\Admin\Translation\PublishTranslations;
use App\Http\Requests\Admin\Translation\RescanTranslations;
use App\Http\Requests\Admin\Translation\UpdateTranslation;
use App\Queries\Filters\FuzzyFilter;
use App\Translations\Export\TranslationsExport;
use App\Translations\LanguageLine;
use App\Translations\Service\TranslationService;
use App\Translations\TranslationsListingDataProcessor;
use App\Translations\TranslationsProcessor;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Maatwebsite\Excel\Facades\Excel;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class TranslationsController extends Controller
{
    protected $translationService;

    public function __construct(
        TranslationService $translationService
    ) {
        $this->translationService = $translationService;
    }

    public function index(IndexTranslation $request, TranslationsListingDataProcessor $translationsListingDataProcessor)
    {
        $data = QueryBuilder::for(LanguageLine::class)
            ->allowedFilters(
                [
                    AllowedFilter::exact('group'),
                    AllowedFilter::custom('search', new FuzzyFilter(
                        'group',
                        'key',
                        'text'
                    )),
                ]
            )
            ->defaultSort('id')
            ->allowedSorts(['id', 'group', 'key', 'text', 'created_at'])
            ->select(['id', 'group', 'key', 'text', 'created_at'])
            ->paginate(request()->get('per_page'))->withQueryString();

        return Inertia::render('Translations/Index', $translationsListingDataProcessor->getProcessedData($data));
    }

    public function update(UpdateTranslation $request, LanguageLine $translation)
    {
        $translation->update($request->validated());

        return redirect()->back()->with(['message' => ___('crafter', 'Translations successfully updated')]);
    }

    public function rescan(RescanTranslations $request, TranslationsProcessor $translationsProcessor)
    {
        $translationsProcessor->scanTranslations();

        return redirect()->back()->with(['message' => ___('crafter', 'Translations successfully re-scanned')]);
    }

    public function publish(PublishTranslations $request, TranslationsProcessor $translationsProcessor)
    {
        $translationsProcessor->publishTranslations();

        return redirect()->back()->with(['message' => ___('crafter', 'Translations successfully published')]);
    }

    public function export(ExportTranslations $request)
    {
        $currentTime = Carbon::now()->toDateTimeString();
        $nameOfExportedFile = 'translations' . $currentTime . '.xlsx';

        return Excel::download(new TranslationsExport($request), $nameOfExportedFile);
    }

    /**
     * @param ImportTranslation $request
     * @return array|JsonResponse|mixed
     */
    public function import(ImportTranslation $request)
    {
        if ($request->hasFile('fileImport')) {
            $chosenLanguage = $request->getChosenLanguage();

            try {
                $collectionFromImportedFile = $this->translationService->getCollectionFromImportedFile($request->file('fileImport'), $chosenLanguage);
            } catch (Exception $e) {
                return response()->json($e->getMessage(), 409);
            }

            $existingTranslations = $this->translationService->getAllTranslationsForGivenLang($chosenLanguage);

            if ($request->input('onlyMissing') === 'true') {
                $filteredCollection = $this->translationService->getFilteredExistingTranslations($collectionFromImportedFile, $existingTranslations);
                $this->translationService->saveCollection($filteredCollection, $chosenLanguage);

                return ['numberOfImportedTranslations' => count($filteredCollection), 'numberOfUpdatedTranslations' => 0];
            } else {
                $collectionWithConflicts = $this->translationService->getCollectionWithConflicts($collectionFromImportedFile, $existingTranslations, $chosenLanguage);
                $numberOfConflicts = $this->translationService->getNumberOfConflicts($collectionWithConflicts);

                if ($numberOfConflicts === 0) {
                    return $this->translationService->checkAndUpdateTranslations($chosenLanguage, $existingTranslations, $collectionWithConflicts);
                }

                return $collectionWithConflicts;
            }
        }

        return response()->json(___('crafter', 'No file imported'), 409);
    }

    public function importResolvedConflicts(UpdateTranslation $request)
    {
        $resolvedConflicts = collect($request->getResolvedConflicts());
        $chosenLanguage = $request->getChosenLanguage();
        $existingTranslations = $this->translationService->getAllTranslationsForGivenLang($chosenLanguage);

        if (! $this->translationService->validImportFile($resolvedConflicts, $chosenLanguage)) {
            return response()->json(___('crafter', 'Wrong syntax in your import'), 409);
        }

        return $this->translationService->checkAndUpdateTranslations($chosenLanguage, $existingTranslations, $resolvedConflicts);
    }
}
