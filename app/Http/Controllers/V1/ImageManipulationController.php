<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\ImageManipulationResource;
use App\Models\Album;
use App\Models\ImageManipulation;
use App\Http\Requests\ResizeImageRequest;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Intervention\Image\Facades\Image;


class ImageManipulationController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @param Request $request
     * @return \Illuminate\Http\Resources\Json\AnonymousResourceCollection
     */
    public function index(Request $request)
    {
        return ImageManipulationResource::collection(ImageManipulation::where('user_id', $request->user()->id)->paginate());
    }

    function byAlbum(Request $request, Album $album){
        if($request->user()->id != $album->user_id){
            return  abort(403, "Unauthorized");
        }

        $where = [
            'album_id' => $album->id,
        ];
        return ImageManipulationResource::collection(ImageManipulation::where($where)->paginate());
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \App\Http\Requests\ResizeImageRequest  $request
     * @return ImageManipulationResource
     */
    public function resize(ResizeImageRequest $request)
    {
        $all = $request->all();

        /** @var UploadedFile|string $image */
        $image = $all['image'];
        unset($all['image']);

        $data = [
            'type' => ImageManipulation::TYPE_RESIZE,
            'data' => json_encode($all),
            'user_id' => $request->user()->id,
        ];

        if(isset($all['album_id'])){
            $album = Album::find($all['album_id']);
            if($request->user()->id != $album->user_id){
                return  abort(403, "Unauthorized");
            }
            $request->user()->id;
            $data['album_id'] = $all['album_id'];
        }

        $dir = 'images/'.Str::random().'/';
        $absolutePath = public_path($dir);
        File::makeDirectory($absolutePath);
        if($image instanceof UploadedFile){
            $data['name'] = $image->getClientOriginalName();

            $filename = pathinfo($data['name'], PATHINFO_FILENAME);
            $extension = $image->getClientOriginalExtension();
            $image->move($absolutePath, $data['name']);
            $originalPath = $absolutePath.$data['name'];
        }else{
            $data['name'] = pathinfo($image, PATHINFO_BASENAME);
            $filename = pathinfo($image, PATHINFO_FILENAME);
            $extension = pathinfo($image, PATHINFO_EXTENSION);
            $originalPath = $absolutePath.$data['name'];
            copy($image, $absolutePath.$data['name']);
        }
        $data['path'] = $dir.$data['name'];

        $w = $all['w'];
        $h = $all['h'] ?? false;

        list($width, $height, $image) = $this->getImageWidthAndHeight($w, $h, $originalPath);
        $resizedFileName = $filename.'-resized.'.$extension;
        $image->resize($width, $height)->save($absolutePath.$resizedFileName);
        $data['output_path'] = $dir.$resizedFileName;

        $imageManipulation = ImageManipulation::create($data);

        return new ImageManipulationResource($imageManipulation);

    }

    /**
     * Display the specified resource.
     *
     * @param Request $request
     * @param \App\Models\ImageManipulation $image
     * @return ImageManipulationResource|\never
     */
    public function show(Request $request, ImageManipulation $image)
    {
        if($request->user()->id != $image->user_id){
            return  abort(403, "Unauthorized");
        }
        return new ImageManipulationResource($image);
    }


    /**
     * Remove the specified resource from storage.
     *
     * @param Request $request
     * @param \App\Models\ImageManipulation $image
     * @return \Illuminate\Http\Response|\never
     */
    public function destroy(Request $request, ImageManipulation $image)
    {
        if($request->user()->id != $image->user_id){
            return  abort(403, "Unauthorized");
        }
        $image->delete();
        return response('', 204);
    }

    protected function getImageWidthAndHeight($w, $h, $originalPath)
    {
        //1000 50% => 5000
        $image = Image::make($originalPath);

        $originalWidth = $image->width();
        $originalHeight = $image->height();

        if(str_ends_with($w, '%')){
            $ratioW = (float)str_replace('%', '', $w);
            $ratioH = $h ? (float)str_replace('%', '', $h): $ratioW;

            $newWidth = $originalHeight * $ratioW / 100;
            $newHeight = $originalHeight * $ratioH / 100;
        }else{
            $newWidth = (float)$w;
            /**
             * $originalWidth - $newWidth
             * $originalHeight - $newHeight
             * -------------------------------------------
             *  $newHeight = $originalHeight * $newWidth/$originalWidth
             */
            $newHeight = $h ? (float)$h : $originalHeight * $newWidth/$originalWidth;
        }

        return [$newWidth, $newHeight, $image];
    }
}

