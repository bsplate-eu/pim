<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\Admin\Media\DestroyMediaRequest;
use App\Http\Requests\Admin\Media\UploadMediaRequest;
use App\Models\UnassignedMedia;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class UnassignedMediaController extends BaseController
{
    use AuthorizesRequests;
    use DispatchesJobs;
    use ValidatesRequests;

    /**
     * @param Request $request
     * @throws AuthorizationException
     * @return JsonResponse
     */
    public function upload(UploadMediaRequest $request): JsonResponse
    {
        if ($request->has('default')) {
            $media = UnassignedMedia::create();

            return response()->json(['media' => $media->getFirstMedia('default')], 200);
        }

        return response()->json(___('crafter', 'File not provided'), 422);
    }

    /**
     * @param Request $request
     * @throws AuthorizationException
     * @return JsonResponse
     */
    public function destroy(DestroyMediaRequest $request, $id): JsonResponse
    {
        $media = Media::findOrFail($id);

        $media->delete();

        return response()->json(___('crafter', 'Successfully deleted'), 200);
    }
}
