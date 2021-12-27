<?php

namespace Common\Managers;

use App\Controllers\BaseController;
use Common\FunctionalTrait;
use Common\Managers\Interfaces\ImagePathManagerInterface;
use Core\Container\Container;

class ImagePathManager extends BaseController implements ImagePathManagerInterface
{
    use FunctionalTrait;

    public function __construct(Container $container)
    {
        parent::__construct($container);
        $this->container = $container;
    }

    public function read(string $resource, int $id): array
    {
        $data = $this->getDaoForObject($resource)
            ->get($id);

        $path = $this->s3->getPath($data['ImagePath'], DOCUMENTS_BUCKET);
        $name = explode('.', $data['ImagePath'])[0];
        return [$path, $name];
    }

    public function create(string $resource, int $id, string $name, string $path): int
    {
        $array = explode(".", $name);
        $ext = end($array);
        $table = $this->getDaoForObject($resource)->getModel()->getTableName();
        $newName = str_replace('tbl_', '', $table) . '_' . $id . "." . $ext;

        $res = $this->s3->put($newName, $path, DOCUMENTS_BUCKET);

        if ($res) {
            $this->getDaoForObject($resource)->update($id, [
                'ImagePath' => $newName
            ]);
            return 1;
        } else {
            return 0;
        }
    }
}