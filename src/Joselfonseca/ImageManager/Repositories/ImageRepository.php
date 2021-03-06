<?php

namespace Joselfonseca\ImageManager\Repositories;

use Joselfonseca\ImageManager\Interfaces\ImageRepositoryInterface;
use Joselfonseca\ImageManager\Interfaces\ImageDbStorageInterface;
use Joselfonseca\ImageManager\Exceptions\AlocateFileException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Laracasts\Commander\Events\EventGenerator;
use Joselfonseca\ImageManager\Commands\UploadFile\Events\FileWasSavedToDisc;
use Joselfonseca\ImageManager\Commands\DeleteFile\Events\FileWasRemovedFromDisc;
use Joselfonseca\ImageManager\Commands\DeleteFile\Events\FileWasRemovedFromDb;
/** Image Manipulation * */
use Intervention\Image\ImageManagerStatic as Image;
use Joselfonseca\ImageManager\Exceptions\ModelNotFoundException as JoseModelNotFoundException;

/**
 * Description of ImageRepository
 *
 * @author jfonseca
 */
class ImageRepository implements ImageRepositoryInterface {

    use EventGenerator;

    private $model;
    private $destination = IM_UPLOADPATH;
    private $command;
    private $file;

    public function __construct(ImageDbStorageInterface $model) {
        $this->model = $model;
    }

    public function uploadFile($command) {
        $filename = $command->file->getClientOriginalName();
        $ext = pathinfo($filename, PATHINFO_EXTENSION);
        $finalFile = md5(md5($filename . date('U'))) . '.' . $ext;
        $upload_success = $command->file->move($this->destination, $finalFile);
        if (empty($upload_success)) {
            throw new AlocateFileException;
        }
        $file = [
            'name' => $finalFile,
            'originalName' => $filename,
            'type' => $upload_success->getMimeType(),
            'path' => $finalFile,
            'size' => $upload_success->getSize()
        ];
        $this->raise(new FileWasSavedToDisc($file));
        return $this->model->saveFileToDb($file);
    }

    public function renderImage($command) {
        $this->command = $command;
        try {
            $i = $this->model->findOrFail($this->command->id);
            $image = Image::make($this->destination . '/' . $i->path);
        } catch (ModelNotFoundException $e) {
            $image = Image::canvas(800, 800, '#cecece');
        }
        if (!empty($this->command->width) && empty($this->command->height)) {
            $image->resize($this->command->width, null, function ($constraint) {
                $constraint->aspectRatio();
                $constraint->upsize();
            });
        } elseif (empty($this->command->width) && !empty($this->command->height)) {
            $image->resize(null, $this->command->height, function ($constraint) {
                $constraint->aspectRatio();
                $constraint->upsize();
            });
        } elseif (!empty($this->command->width) && !empty($this->command->height)) {
            $image->resize($this->command->width, $this->command->height, function ($constraint) {
                $constraint->aspectRatio();
                $constraint->upsize();
            });
        }
        return $image->response();
    }

    public function getFiles() {
        return $this->model->orderBy('created_at', 'desc')->paginate(12);
    }

    public function DeleteFile($comand) {
        try {
            $this->file = $this->model->getFileById($comand->id);
        } catch (ModelNotFoundException $e) {
            throw new JoseModelNotFoundException;
        }
        $this->_removeFileFromDisk();
        $this->_removeFileFromDb();
    }
    
    private function _removeFileFromDisk(){
        $file = $this->destination.'/'.$this->file->path;
        unlink($file);
        $this->raise(new FileWasRemovedFromDisc($this->file));
    }
    
    private function _removeFileFromDb(){
        $file = $this->file->getFileInfo();
        $this->file->DeleteFile();
        $this->raise(new FileWasRemovedFromDb($file));
    }

}
