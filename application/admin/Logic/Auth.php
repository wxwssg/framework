<?php

// +----------------------------------------------------------------------
// | ThinkAdmin
// +----------------------------------------------------------------------
// | 版权所有 2014~2017 广州楚才信息科技有限公司 [ http://www.cuci.cc ]
// +----------------------------------------------------------------------
// | 官方网站: http://think.ctolog.com
// +----------------------------------------------------------------------
// | 开源协议 ( https://mit-license.org )
// +----------------------------------------------------------------------
// | github开源项目：https://github.com/zoujingli/ThinkAdmin
// +----------------------------------------------------------------------

namespace app\admin\Logic;

use library\tools\Data;
use library\tools\Node;
use think\Db;

/**
 * 权限访问管理
 * Class Auth
 * @package app\admin\Logic
 */
class Auth
{

    /**
     * 获取系统代码节点
     * @param array $nodes
     * @return array
     */
    public static function get($nodes = [])
    {
        $alias = Db::name('SystemNode')->column('node,is_menu,is_auth,is_login,title');
        $ignore = ['index', 'wechat/review', 'admin/plugs', 'admin/login', 'admin/index'];
        foreach (Node::getTree(env('app_path')) as $thr) {
            foreach ($ignore as $str) {
                if (stripos($thr, $str) === 0) {
                    continue 2;
                }
            }
            $tmp = explode('/', $thr);
            list($one, $two) = ["{$tmp[0]}", "{$tmp[0]}/{$tmp[1]}"];
            $nodes[$one] = array_merge(isset($alias[$one]) ? $alias[$one] : ['node' => $one, 'title' => '', 'is_menu' => 0, 'is_auth' => 0, 'is_login' => 0], ['pnode' => '']);
            $nodes[$two] = array_merge(isset($alias[$two]) ? $alias[$two] : ['node' => $two, 'title' => '', 'is_menu' => 0, 'is_auth' => 0, 'is_login' => 0], ['pnode' => $one]);
            $nodes[$thr] = array_merge(isset($alias[$thr]) ? $alias[$thr] : ['node' => $thr, 'title' => '', 'is_menu' => 0, 'is_auth' => 0, 'is_login' => 0], ['pnode' => $two]);
        }
        foreach ($nodes as &$node) {
            list($node['is_auth'], $node['is_menu'], $node['is_login']) = [intval($node['is_auth']), intval($node['is_menu']), empty($node['is_auth']) ? intval($node['is_login']) : 1];
        }
        return $nodes;
    }

    /**
     * 检查用户节点权限
     * @param string $node 节点
     * @return bool
     */
    public static function checkAuthNode($node)
    {
        list($module, $controller, $action) = explode('/', str_replace(['?', '=', '&'], '/', $node . '///'));
        $currentNode = Node::parseString("{$module}/{$controller}") . strtolower("/{$action}");
        // 后台入口无需要验证权限
        if (stripos($node, 'admin/index') === 0) {
            return true;
        }
        // 超级管理员无需要验证权限
        if (session('user.username') === 'admin') {
            return true;
        }
        // 未配置权限的节点默认放行
        if (!in_array($currentNode, self::getAuthNode())) {
            return true;
        }
        // 用户指定角色授权放行
        return in_array($currentNode, (array)session('user.nodes'));
    }

    /**
     * 获取授权节点
     * @return array
     */
    public static function getAuthNode()
    {
        $nodes = cache('need_access_node');
        if (empty($nodes)) {
            $nodes = Db::name('SystemNode')->where(['is_auth' => '1'])->column('node');
            cache('need_access_node', $nodes);
        }
        return $nodes;
    }

    /**
     * 应用用户权限节点
     * @return bool
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public static function applyAuthNode()
    {
        cache('need_access_node', null);
        if (($userid = session('user.id'))) {
            session('user', Db::name('SystemUser')->where(['id' => $userid])->find());
        }
        if (($authorize = session('user.authorize'))) {
            $where = [['status', 'eq', '1'], ['id', 'in', explode(',', $authorize)]];
            $auths = Db::name('SystemAuth')->where($where)->column('id');
            if (empty($auths)) {
                return session('user.nodes', []);
            }
            $nodes = Db::name('SystemAuthNode')->whereIn('auth', $auths)->column('node');
            return session('user.nodes', $nodes);
        }
        return false;
    }

    /**
     * 获取授权后的菜单
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public static function getAuthMenu()
    {
        self::applyAuthNode();
        $list = Db::name('SystemMenu')->where(['status' => '1'])->order('sort asc,id asc')->select();
        return self::buildMenuData(Data::arr2tree($list), self::get(), !!session('user'));
    }

    /**
     * 后台主菜单权限过滤
     * @param array $menus 当前菜单列表
     * @param array $nodes 系统权限节点数据
     * @param bool $isLogin 是否已经登录
     * @return array
     */
    private static function buildMenuData($menus, $nodes, $isLogin)
    {
        foreach ($menus as $key => &$menu) {
            !empty($menu['sub']) && $menu['sub'] = self::buildMenuData($menu['sub'], $nodes, $isLogin);
            if (!empty($menu['sub'])) {
                $menu['url'] = '#';
            } elseif (preg_match('/^https?\:/i', $menu['url'])) {
                continue;
            } elseif ($menu['url'] !== '#') {
                $node = join('/', array_slice(explode('/', preg_replace('/[\W]/', '/', $menu['url'])), 0, 3));
                $menu['url'] = url($menu['url']) . (empty($menu['params']) ? '' : "?{$menu['params']}");
                if (isset($nodes[$node]) && $nodes[$node]['is_login'] && empty($isLogin)) {
                    unset($menus[$key]);
                } elseif (isset($nodes[$node]) && $nodes[$node]['is_auth'] && $isLogin && !self::checkAuthNode($node)) {
                    unset($menus[$key]);
                }
            } else {
                unset($menus[$key]);
            }
        }
        return $menus;
    }

}