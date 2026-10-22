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
        // Unique per upload: the same ImagePath must always mean the same bytes,
        // because image URLs carry it as a cache version (truck-backend Avatar).
        $newName = str_replace('tbl_', '', $table) . '_' . $id . '_' . bin2hex(random_bytes(4)) . "." . $ext;
        $previous = $this->getDaoForObject($resource)->get($id)['ImagePath'] ?? null;

        $res = $this->s3->put($newName, $path, DOCUMENTS_BUCKET);

        if ($res) {
            $this->getDaoForObject($resource)->update($id, [
                'ImagePath' => $newName
            ]);
            if (!empty($previous)) {
                $this->s3->delete($previous, DOCUMENTS_BUCKET);
            }
            return 1;
        } else {
            return 0;
        }
    }

    public function delete(string $resource, int $id): int
    {
        $data = $this->getDaoForObject($resource)->get($id);
        if (!empty($data['ImagePath'])) {
            $this->s3->delete($data['ImagePath'], DOCUMENTS_BUCKET);
        }
        return $this->getDaoForObject($resource)->update($id, ['ImagePath' => null]);
    }
}