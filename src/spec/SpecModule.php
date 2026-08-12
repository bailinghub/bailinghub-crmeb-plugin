<?php
// +----------------------------------------------------------------------
// | 能力声明模块接口（ACC 声明层）
// | 每个业务领域一个声明模块：原子能力（元能力）+ 自己的 path 映射
// | 设计原则：一个工具 = 一个资源 + 一个动作，组合编排交给中枢 AI
// +----------------------------------------------------------------------
namespace app\bailing\spec;

use Bailing\Connect\ToolSpec;

interface SpecModule
{
    /**
     * 本模块工具名 => spec path 映射
     * @return array<string,string>
     */
    public static function paths();

    /**
     * 把本模块的能力声明注册到 spec
     */
    public static function register(ToolSpec $spec);
}
