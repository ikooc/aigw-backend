<?php
/**
 * 站点公开配置（登录页/注册页引导，仅 CORS，无需登录）
 */

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Repositories\GatewayRepository;
use App\Repositories\ProviderRepository;
use App\Support\ClientIp;
use App\Support\MailService;

class SiteController
{
    public function __construct(
        private GatewayRepository $gatewayRepository,
        private MailService $mailService,
        private ProviderRepository $providerRepository,
    ) {}

    /** GET /api/site-config */
    public function config(Request $request): Response
    {
        // 站点名称以「后台可改」为准，未配置时回落 config/app.php 的 app.name
        $appName = (string) $this->gatewayRepository->get('app_name', config('app.name', 'AI 聚合网关'));

        // 落地页「支持多提供商」区块的品牌墙数据源：只回内置公开品牌，
        // 不含用户自建实例（见 ProviderRepository::builtinCatalog 的注释）。
        $catalog  = $this->providerRepository->builtinCatalog();
        $enabled  = array_values(array_filter($catalog, static fn(array $p): bool => $p['enabled']));

        // 弹窗公告（注册页 / 总览面板）：走公开接口下发，未登录的注册页也能拿到。
        // 关闭时**不返回标题与正文**，避免把尚未启用的文案泄漏到公开响应里。
        $noticeEnabled = (bool) $this->gatewayRepository->get('notice_enabled', false);
        $notice = [
            'enabled' => $noticeEnabled,
            'target'  => (string) $this->gatewayRepository->get('notice_target', 'all'),
            'version' => (int) $this->gatewayRepository->get('notice_version', 0),
        ];
        if ($noticeEnabled) {
            $notice['title']   = (string) $this->gatewayRepository->get('notice_title', '');
            $notice['content'] = (string) $this->gatewayRepository->get('notice_content', '');
            $notice['type']    = (string) $this->gatewayRepository->get('notice_type', 'info');
            // 公告上的「跳转加群」按钮。群号/链接同样只在公告开启时下发，
            // 与标题正文口径一致（关闭即不泄漏后台配置）。
            $notice['group_enabled'] = (bool) $this->gatewayRepository->get('notice_group_enabled', false);
            $notice['group_qq']      = (string) $this->gatewayRepository->get('notice_group_qq', '');
            $notice['group_url']     = (string) $this->gatewayRepository->get('notice_group_url', '');
        }

        return Response::json([
            'ok'   => true,
            'data' => [
                'app_name'         => $appName,
                'app_subtitle'     => (string) $this->gatewayRepository->get('app_subtitle', 'Aggregator'),
                'app_desc'         => (string) $this->gatewayRepository->get(
                    'app_desc',
                    '多供应商账号池 · 统一 OpenAI 兼容出口'
                ),
                'allow_register'   => (bool) $this->gatewayRepository->get('allow_register', true),
                'captcha_enabled'  => (bool) $this->gatewayRepository->get('captcha_enabled', true),
                // 注册是否需要邮箱验证（开关打开 **且** SMTP 配置可用）
                'mail_verify'      => (bool) $this->gatewayRepository->get('mail_verify_required', false)
                    && $this->mailService->ready(),
                'mail_ready'       => $this->mailService->ready(),
                'admin_login_path' => admin_login_path(),
                // 多提供商（品牌墙）：enabled 为真说明该平台当前可路由
                'providers'        => $catalog,
                'provider_count'   => count($enabled),
                // 破甲（思维注入）总闸门：只报「系统内置思维」这一层的开关，
                // 用户级开关属个人配置，不在公开接口暴露。
                'persona_enabled'  => (bool) $this->gatewayRepository->get('system_persona_enabled', false),
                'persona_override' => (bool) $this->gatewayRepository->get('persona_override_client', false),
                // 弹窗公告（注册页 / 总览面板，后台可控制）
                'notice'           => $notice,
            ],
        ]);
    }

    /**
     * GET /api/client-ip —— 真实客户端 IP 诊断
     *
     * 为什么公开：这是**部署自查**用接口。CDN 场景下「访客 IP 是否被正确还原」
     * 只能从真实 HTTP 链路看出来 —— CLI 没有 REMOTE_ADDR / XFF，测不出来
     * （本项目已因此踩过坑：CLI 绿灯但线上全站共用一个限流桶）。
     *
     * 只回显**请求者自己的** IP 与判定来源，不泄漏任何服务端信息，
     * 因此公开无风险；但为免被当成探测入口，仍加 CORS 且不改任何状态。
     */
    public function clientIp(Request $request): Response
    {
        $info = ClientIp::explain($request);

        return Response::json([
            'ok'   => true,
            'data' => [
                'ip'                 => $info['resolved'],
                'source'             => $info['source'],
                'origin_token_set'   => $info['origin_token_set'] === 'yes',
                'origin_token_ok'    => $info['token_matched'] === 'yes',
                'client_ip_header'   => $info['client_ip_header'],
                'client_ip_value'    => $info['client_ip_value'],
                'x_forwarded_for'    => $info['x_forwarded_for'],
                'remote_addr'        => $info['remote_addr'],
                // 判定说明：source 取值含义（前端/人工核对用）
                'source_legend'      => [
                    'origin_token' => '来自 CDN 下发的客户端 IP 头（回源令牌校验通过）—— 正常',
                    'xff_proxy'    => '来自可信代理的 X-Forwarded-For 右侧首个非可信项 —— 正常',
                    'xff_fallback' => '整条 XFF 均为可信代理，退回最左合法项（少见）',
                    'remote'       => 'TCP 直连地址（无代理，或令牌未通过）',
                ],
            ],
        ]);
    }
}