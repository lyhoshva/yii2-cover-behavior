<?php
/**
 * Created by PhpStorm.
 * User: lyhoshva
 * Date: 13.09.16
 * Time: 12:17
 */

namespace lyhoshva\Cover;

use Imagine\Gd\Imagine;
use Imagine\Image\Box;
use Imagine\Image\ManipulatorInterface;
use Imagine\Image\Point;
use InvalidArgumentException;
use Yii;
use yii\base\Behavior;
use yii\base\Exception;
use yii\base\InvalidConfigException;
use yii\base\UnknownPropertyException;
use yii\db\ActiveRecord;
use yii\helpers\FileHelper;
use yii\helpers\VarDumper;
use yii\web\UploadedFile;

/**
 *
 * @property string $fileName generated filename
 * @property string $modelFullFileName
 * @property string $filePath newly generated path for file
 * @property string $modelFilePath file path stored in model attribute
 * @property string $fileExtension
 *
 * @property \yii\base\Model $owner
 */
class CoverBehavior extends Behavior
{
    const THUMBNAIL_INSET = ManipulatorInterface::THUMBNAIL_INSET;
    const THUMBNAIL_OUTBOUND = ManipulatorInterface::THUMBNAIL_OUTBOUND;

    /** @var string real existing model attribute that contain image name */
    public $modelAttribute;

    /** @var string specify model attribute where file path will be stored.
     * It need when $path attribute configured as callable function.
     * If $path == callable and this attribute empty, path will be stored as prefix in $modelAttribute */
    public $modelAttributeFilePath = null;

    /** @var string virtual attribute that will be placed in owner model object */
    public $relationAttribute = 'image';

    /** @var array options to generate thumbnails for incoming image */
    public $thumbnails = array();

    /** @var boolean is need to get request attributes with simple names like `bar` instead of `Foo[bar]`*/
    public $simpleRequest = false;
    
    /**
     * @var string|callable path to store file. Default value use `'@frontend/web/uploads'`.
     * Callback function should has next template:
     * function($ownerActiveRecord) {
     *      return [string];
     * }
     */
    public $path;

    /** @var string path to watermark image file. Default NULL */
    public $watermark = null;

    /** @var callable Callback function to generate file name */
    public $fileNameGenerator;

    private $_submitFile, $_fileName, $_filePath;
    private $fileNameRegexp = '/[a-zA-Z0-9\-_]*\.\w{3,4}$/i';

    /** @inheritdoc */
    public function init()
    {
        parent::init();

        if (empty($this->path)) {
            $this->path = Yii::getAlias('@frontend/web/uploads');
        }
        if (empty($this->fileNameGenerator)) {
            /**
             * @param \yii\web\UploadedFile $submitFile
             * @return string
             */
            $this->fileNameGenerator = function ($submitFile) {
                return $submitFile->baseName . '_' . uniqid();
            };
        } elseif (!is_callable($this->fileNameGenerator)) {
            throw new InvalidArgumentException('$fileNameGenerator should be callback function');
        }
        if (!empty($this->thumbnails)) {
            foreach ($this->thumbnails as &$thumbnail) {
                if (empty($thumbnail['prefix'])) {
                    throw new InvalidArgumentException('$thumbnails[\'prefix\'] can not be empty');
                }
                if (empty($thumbnail['width'])) {
                    throw new InvalidArgumentException('$thumbnails[\'width\'] have to be not empty');
                }
                if (empty($thumbnail['height'])) {
                    $thumbnail['height'] = $thumbnail['width'];
                }
                if (!is_numeric($thumbnail['width']) || !is_numeric($thumbnail['height'])) {
                    throw new InvalidArgumentException('$thumbnails[\'width\'] and $thumbnails[\'height\'] have to be a number');
                }
                if (empty($thumbnail['mode'])) {
                    $thumbnail['mode'] = ManipulatorInterface::THUMBNAIL_INSET;
                } elseif (!in_array($thumbnail['mode'],
                    [ManipulatorInterface::THUMBNAIL_INSET, ManipulatorInterface::THUMBNAIL_OUTBOUND])
                ) {
                    throw new InvalidArgumentException('Undefined mode in $thumbnail[\'mode\']');
                }
            }
        }
    }

    /**
     * PHP getter magic method.
     * This method is overridden so that relation attribute can be accessed like property.
     *
     * @param string $name property name
     *
     * @throws UnknownPropertyException if the property is not defined
     * @return mixed property value
     */
    public function __get($name)
    {
        try {
            return parent::__get($name);
        } catch (UnknownPropertyException $e) {
            if ($name === $this->relationAttribute) {
                if (is_null($this->_submitFile)) {
                    $this->_submitFile = $this->owner->{$this->modelAttribute};
                }
                return $this->_submitFile;
            }
            throw $e;
        }
    }

    /**
     * PHP setter magic method.
     * This method is overridden so that relation attribute can be accessed like property.
     * @param string $name property name
     * @param mixed $value property value
     * @throws UnknownPropertyException if the property is not defined
     */
    public function __set($name, $value)
    {
        try {
            parent::__set($name, $value);
        } catch (UnknownPropertyException $e) {
            if ($name === $this->relationAttribute) {
                $this->_submitFile = $value;
            } else {
                throw $e;
            }
        }
    }

    /** @inheritdoc */
    public function canGetProperty($name, $checkVars = true)
    {
        return parent::canGetProperty($name, $checkVars) || $name === $this->relationAttribute;
    }

    /** @inheritdoc */
    public function canSetProperty($name, $checkVars = true)
    {
        return parent::canSetProperty($name, $checkVars) || $name === $this->relationAttribute;
    }

    /** @inheritdoc */
    public function events()
    {
        return [
            ActiveRecord::EVENT_BEFORE_VALIDATE => 'loadImage',
            ActiveRecord::EVENT_BEFORE_INSERT => 'saveImage',
            ActiveRecord::EVENT_BEFORE_UPDATE => 'updatePathAndSaveImage',
            ActiveRecord::EVENT_AFTER_DELETE => 'deleteImage',
        ];
    }

    public function loadImage()
    {
        if ($this->simpleRequest) {
            $this->_submitFile = UploadedFile::getInstanceByName($this->relationAttribute);
        } else {
            $this->_submitFile = UploadedFile::getInstance($this->owner, $this->relationAttribute);
        }
        return true;
    }

    public function saveImage()
    {
        $owner = $this->owner;
        $table_attribute = $this->modelAttribute;
        if ($this->_submitFile instanceof UploadedFile) {
            $isSaved = FileHelper::createDirectory($this->filePath);
            $isSaved = $isSaved && $this->_submitFile->saveAs(
                    $this->filePath . $this->fileName . '.' . $this->fileExtension);

            if (!$isSaved) {
                throw new Exception($this->_submitFile->name . ' not saved.');
            }

            $this->setModelFullFileName($this->filePath, $this->fileName . '.' . $this->fileExtension);
            $this->_submitFile = null;

            $this->addWatermark($owner->$table_attribute);
            $this->generateThumbnail($owner->$table_attribute);
        }
        return true;
    }

    public function updatePathAndSaveImage()
    {
        if (empty($this->_submitFile)) {
            return true;
        }
        if ($this->_submitFile instanceof UploadedFile) {
            $this->deleteImage();
            return $this->saveImage();
        }
        if (is_callable($this->path) && $this->modelFilePath !== $this->filePath) {
            if ($this->modelAttributeFilePath) {
                $oldFilePath = $this->owner->{$this->modelAttributeFilePath};
            } else {
                $oldFilePath = preg_replace($this->fileNameRegexp, '', $this->owner->{$this->modelAttribute});
            }
            $isMoved = FileHelper::createDirectory($this->filePath);

            if ($isMoved = $isMoved && rename($this->modelFullFileName,
                    $this->filePath . $this->fileName . '.' . $this->fileExtension)
            ) {
                $this->setModelFullFileName($this->filePath, $this->fileName . '.' . $this->fileExtension);
            }
            if (self::isDirectoryEmpty($oldFilePath)) {
                FileHelper::removeDirectory($oldFilePath);
            };
            return $isMoved;
        }
        return true;
    }

    public function deleteImage()
    {
        $file = $this->modelFilePath . $this->modelFullFileName;
        if (!is_callable($this->path)) {
            $file = $this->path . $file;
        }
        if (file_exists($file) && !is_dir($file)) {
            unlink($file);
        }
        if (!empty($this->modelAttributeFilePath)) {
            $this->deleteThumbnails($this->owner->{$this->modelAttribute}, $this->modelFilePath);
        } elseif (!empty($this->owner->{$this->modelAttribute})) {
            preg_match($this->fileNameRegexp, $this->owner->{$this->modelAttribute}, $file_name);
            $this->deleteThumbnails($file_name[0]);
        }
    }

    /**
     * Unlink all thumbnails of specified file
     * @param $originalFileName String Original file name
     * @param $filePath String path to thumbnails
     */
    protected function deleteThumbnails($originalFileName, $filePath = null)
    {
        foreach ($this->thumbnails as $thumbnail) {
            $file_path = !empty($filePath) ? $filePath : $this->filePath . $thumbnail['prefix'] . $originalFileName;
            if (file_exists($file_path)) {
                unlink($file_path);
            }
        }
    }

    /**
     * Create watermark on uploaded image that stored in $watermark attribute if it not empty
     * @param $path String path to saved image
     */
    protected function addWatermark($path)
    {
        if (!empty($this->watermark)) {
            $imagine = new Imagine();
            $watermark = $imagine->open($this->watermark);
            $image = $imagine->open($path);
            $image_size = $image->getSize();
            $watermark = $watermark->crop(new Point(0, 0), new Box($image_size->getWidth(), $image_size->getHeight()));
            $image = $image->paste($watermark, new Point(0, 0));
            $image->save();
        }
    }

    /**
     * Generate thumbnail according configures in $thumbnails attribute. If it empty - thumbnails will not configure.
     * @param $fileName String file name
     */
    protected function generateThumbnail($fileName)
    {
        if (!empty($this->thumbnails)) {
            $imagine = new Imagine();
            $imagine = $imagine->open($this->filePath . $fileName);
            foreach ($this->thumbnails as $thumbnail) {
                $imagine->thumbnail(new Box($thumbnail['width'], $thumbnail['height']), $thumbnail['mode'])
                    ->save($this->filePath . $thumbnail['prefix'] . $fileName);
            }
        }
    }

    protected function getModelFullFileName()
    {
        if (empty($this->modelAttributeFilePath)) {
            return $this->owner->{$this->modelAttribute};
        } else {
            return $this->owner->{$this->modelAttributeFilePath} . $this->owner->{$this->modelAttribute};
        }
    }

    protected function setModelFullFileName($filePath, $fileName)
    {
        if ($this->modelAttributeFilePath) {
            $this->owner->{$this->modelAttributeFilePath} = $filePath;
            $this->owner->{$this->modelAttribute} = $fileName;
        } elseif (is_callable($this->path)) {
            $this->owner->{$this->modelAttribute} = $filePath . $fileName;
        } else {
            $this->owner->{$this->modelAttribute} = $fileName;
        }
    }

    protected function getModelFilePath()
    {
        if ($this->modelAttributeFilePath) {
            return $this->owner->{$this->modelAttributeFilePath};
        } elseif (is_callable($this->path)) {
            $modelFile = $this->owner->{$this->modelAttribute};
            return substr($modelFile, 0, 1 + strrpos($modelFile, '/', -1));
        } else {
            return null;
        }
    }

    protected function getFileExtension()
    {
        if ($this->_submitFile instanceof UploadedFile) {
            return $this->_submitFile->extension;
        } elseif (!empty($this->owner->{$this->modelAttribute})) {
            $modelFile = $this->owner->{$this->modelAttribute};
            return substr($modelFile, 1 + strrpos($modelFile, '.', -1));
        }
        throw new Exception("$this->relationAttribute and \"$this->modelAttribute\" hasn't uploaded files.");
    }

    protected function getFilePath()
    {
        if (empty($this->_filePath)) {
            $this->_filePath = $this->getParamValue($this->path);
        }
        return $this->_filePath;
    }

    protected function getFileName()
    {
        if (empty($this->_fileName)) {
            $this->_fileName = $this->getParamValue($this->fileNameGenerator);
        }
        return $this->_fileName;
    }

    /**
     * @param callable|String $paramValue configured param value
     * @return string generated value
     * @throws InvalidConfigException if callback function didn't return String
     */
    protected function getParamValue($paramValue)
    {
        if (is_callable($paramValue)) {
            $result = call_user_func($paramValue, $this->_submitFile, $this->owner);
            if (!is_string($result)) {
                throw new InvalidConfigException('Callback function should return a String value. Result is '
                    . VarDumper::dumpAsString($result) . ' for '
                    . VarDumper::dumpAsString($paramValue));
            }
            return $result;
        }
        return $paramValue;
    }

    protected static function isDirectoryEmpty($dir)
    {
        if (!is_readable($dir)) return null;
        $handle = opendir($dir);
        while (false !== ($entry = readdir($handle))) {
            if ($entry != "." && $entry != "..") {
                return false;
            }
        }
        return true;
    }
}
