<?php
declare(strict_types=1);

namespace App\Controller\Admin\Api;


use App\Controller\Base\API\Manage;
use App\Entity\CreateObjectEntity;
use App\Entity\DeleteBatchEntity;
use App\Entity\QueryTemplateEntity;
use App\Interceptor\ManageSession;
use App\Interceptor\Waf;
use App\Model\ManageLog;
use App\Service\Query;
use Illuminate\Database\Eloquent\Relations\Relation;
use Kernel\Annotation\Inject;
use Kernel\Annotation\Interceptor;
use Kernel\Annotation\Post;
use Kernel\Exception\JSONException;

#[Interceptor([ManageSession::class], Interceptor::TYPE_API)]
class Pay extends Manage
{

    #[Inject]
    private \App\Service\Pay $pay;

    #[Inject]
    private Query $query;

    /**
     * @return array
     */
    public function data(): array
    {
        $map = $_POST;
        $queryTemplateEntity = new QueryTemplateEntity();
        $queryTemplateEntity->setModel(\App\Model\Pay::class);
        $queryTemplateEntity->setLimit((int)$_POST['limit']);
        $queryTemplateEntity->setPage((int)$_POST['page']);
        $queryTemplateEntity->setPaginate(true);
        $queryTemplateEntity->setWhere($map);
        $queryTemplateEntity->setOrder("sort", "asc");
        $data = $this->query->findTemplateAll($queryTemplateEntity)->toArray();
        $json = $this->json(200, null, $data['data']);
        $json['count'] = $data['total'];
        return $json;
    }


    /**
     * @return array
     * @throws JSONException
     */
    public function save(): array
    {
        $map = $_POST;
        if ($map['id'] == 1) {
            throw new JSONException("系统内置，无法操作");
        }
        $createObjectEntity = new CreateObjectEntity();
        $createObjectEntity->setModel(\App\Model\Pay::class);
        $createObjectEntity->setMap($map);
        $createObjectEntity->setCreateDate("create_time");
        $save = $this->query->createOrUpdateTemplate($createObjectEntity);
        if (!$save) {
            throw new JSONException("保存失败，请检查信息填写是否完整");
        }

        ManageLog::log($this->getManage(), "[修改/新增]支付接口");
        return $this->json(200, '（＾∀＾）保存成功');
    }


    /**
     * @return array
     * @throws JSONException
     */
    public function del(): array
    {
        if (in_array("1", $_POST['list'])) {
            throw new JSONException("请不要将内置支付也选中");
        }
        $deleteBatchEntity = new DeleteBatchEntity();
        $deleteBatchEntity->setModel(\App\Model\Pay::class);
        $deleteBatchEntity->setList($_POST['list']);
        $count = $this->query->deleteTemplate($deleteBatchEntity);
        if ($count == 0) {
            throw new JSONException("没有移除任何数据");
        }

        ManageLog::log($this->getManage(), "[删除]支付接口，共计：" . count($_POST['list']));
        return $this->json(200, '（＾∀＾）移除成功');
    }

    /**
     * 获取插件列表
     * @return array
     */
    public function getPlugins(): array
    {
        $plugins = $this->pay->getPlugins();
        $appStore = (array)json_decode((string)file_get_contents(BASE_PATH . "/runtime/plugin/store.cache"), true);
        foreach ($plugins as $index => $plugin) {
            if (!array_key_exists($plugin["id"], $appStore)) {
                $plugins[$index]['icon'] = "/favicon.ico";
            } else {
                $plugins[$index]['icon'] = \App\Service\App::APP_URL . $appStore[$plugin["id"]]['icon'];
            }
        }

        return $this->json(200, 'success', $plugins);
    }

    /**
     * 获取插件日志
     * @param string $handle
     * @return array
     */
    public function getPluginLog(#[Post] string $handle): array
    {
        $pluginLog = $this->pay->getPluginLog($handle);
        return $this->json(200, 'success', ['log' => $pluginLog]);
    }

    /**
     * @param string $handle
     * @return array
     */
    public function ClearPluginLog(#[Post] string $handle): array
    {
        $this->pay->ClearPluginLog($handle);
        ManageLog::log($this->getManage(), "清空了支付插件({$handle})的日志");
        return $this->json(200, 'success');
    }

    /**
     * @throws JSONException
     */
    public function setPluginConfig(): array
    {
        $map = $_POST;
        if (!$map['id'] === "" || !isset($map['id'])) {
            throw new JSONException("插件不存在");
        }
        foreach ($map as $k => $v) {
            $map[$k] = urldecode($v);
        }
        $id = $map['id'];
        unset($map['id']);
        setConfig($map, BASE_PATH . '/app/Pay/' . $id . '/Config/Config.php');

        ManageLog::log($this->getManage(), "修改了支付插件({$id})的配置信息");
        return $this->json(200, '修改成功');
    }
}