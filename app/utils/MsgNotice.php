<?php

namespace app\utils;
use think\facade\Db;
use app\lib\CertHelper;
use app\lib\DeployHelper;

class MsgNotice
{
    private static $sitename = '聚合DNS管理系统';

    public static function send($action, $task, $result)
    {
        if ($action == 1) {
            $mail_title = 'DNS容灾切换-发生告警通知';
            $mail_content = '尊敬的系统管理员，您好：<br/>您的域名 <b>'.$task['domain'].'</b> 的 <b>'.$task['main_value'].'</b> 记录发生了异常';
            if ($task['type'] == 2) {
                $mail_content .= '，已自动切换为备用解析记录 '.$task['backup_value'].' ';
            } elseif ($task['type'] == 1) {
                $mail_content .= '，已自动暂停解析';
            } else {
                $mail_content .= '，请及时处理';
            }
            if (!empty($result['errmsg'])) {
                $mail_content .= '。<br/>异常信息：'.$result['errmsg'];
            }
        } else {
            $mail_title = 'DNS容灾切换-恢复正常通知';
            $mail_content = '尊敬的系统管理员，您好：<br/>您的域名 <b>'.$task['domain'].'</b> 的 <b>'.$task['main_value'].'</b> 记录已恢复正常';
            if ($task['type'] == 2) {
                $mail_content .= '，已自动切换回当前解析记录';
            } elseif ($task['type'] == 1) {
                $mail_content .= '，已自动开启解析';
            }
            $lasttime = convert_second(time() - $task['switchtime']);
            $mail_content .= '。<br/>异常持续时间：'.$lasttime;
        }
        if (!empty($task['remark'])) {
            $mail_title .= '('.$task['remark'].')';
        }
        if (!empty($task['remark'])) {
            $mail_content .= '<br/>备注：'.$task['remark'];
        }
        $mail_content .= '<br/>'.self::$sitename.'<br/>'.date('Y-m-d H:i:s');

        if (config_get('notice_mail') == 1) {
            $mail_name = config_get('mail_recv') ? config_get('mail_recv') : config_get('mail_name');
            self::send_mail($mail_name, $mail_title, $mail_content);
        }
        if (config_get('notice_wxtpl') == 1) {
            $content = str_replace(['<br/>', '<b>', '</b>'], ["\n\n", '**', '**'], $mail_content);
            self::send_wechat_tplmsg($mail_title, $content);
        }
        if (config_get('notice_tgbot') == 1) {
            $content = str_replace('<br/>', "\n", $mail_content);
            $content = "<strong>".$mail_title."</strong>\n".$content;
            self::send_telegram_bot($content);
        }
    }

    public static function cert_send($id, $result)
    {
        $row = Db::name('cert_order')->field('id,aid,issuetime,expiretime,issuer,status,error')->where('id', $id)->find();
        if (!$row) return;
        $type = Db::name('cert_account')->where('id', $row['aid'])->value('type');
        $domainList = Db::name('cert_domain')->where('oid', $id)->column('domain');
        if (empty($domainList)) return;
        if ($result) {
            if (count($domainList) > 1) {
                $mail_title = $domainList[0] . '等' . count($domainList) . '个域名SSL证书签发成功通知';
            } else {
                $mail_title = $domainList[0] . '域名SSL证书签发成功通知';
            }
            $mail_content = '尊敬的用户，您好：您的SSL证书已签发成功！<br/>证书账户：'.CertHelper::$cert_config[$type]['name'].'('.$row['aid'].')<br/>证书域名：'.implode('、', $domainList).'<br/>签发时间：'.$row['issuetime'].'<br/>到期时间：'.$row['expiretime'].'<br/>颁发机构：'.$row['issuer'];
        } else {
            $status_arr = [0 => '失败', -1 => '购买证书失败', -2 => '创建订单失败', -3 => '添加DNS失败', -4 => '验证DNS失败', -5 => '验证订单失败', -6 => '订单验证未通过', -7 => '签发证书失败'];
            if(count($domainList) > 1){
                $mail_title = $domainList[0].'等'.count($domainList).'个域名SSL证书'.$status_arr[$row['status']].'通知';
            }else{
                $mail_title = $domainList[0].'域名SSL证书'.$status_arr[$row['status']].'通知';
            }
            $mail_content = '尊敬的用户，您好：您的SSL证书'.$status_arr[$row['status']].'！<br/>证书账户：'.CertHelper::$cert_config[$type]['name'].'('.$row['aid'].')<br/>证书域名：'.implode('、', $domainList).'<br/>失败时间：'.date('Y-m-d H:i:s').'<br/>失败原因：'.$row['error'];
        }
        $mail_content .= '<br/>'.self::$sitename.'<br/>'.date('Y-m-d H:i:s');

        if (config_get('cert_notice_mail') == 1 || config_get('cert_notice_mail') == 2 && !$result) {
            $mail_name = config_get('mail_recv') ? config_get('mail_recv') : config_get('mail_name');
            self::send_mail($mail_name, $mail_title, $mail_content);
        }
        if (config_get('cert_notice_wxtpl') == 1 || config_get('cert_notice_wxtpl') == 2 && !$result) {
            $content = str_replace(['<br/>', '<b>', '</b>'], ["\n\n", '**', '**'], $mail_content);
            self::send_wechat_tplmsg($mail_title, $content);
        }
        if (config_get('cert_notice_tgbot') == 1 || config_get('cert_notice_tgbot') == 2 && !$result) {
            $content = str_replace('<br/>', "\n", $mail_content);
            $content = "<strong>" . $mail_title . "</strong>\n" . $content;
            self::send_telegram_bot($content);
        }
        Db::name('cert_order')->where('id', $id)->update(['issend' => 1]);
    }

    public static function deploy_send($id, $result)
    {
        $row = Db::name('cert_deploy')->field('id,aid,oid,remark,status,error')->where('id', $id)->find();
        if (!$row) return;
        $account = Db::name('cert_account')->field('id,type,name,remark')->where('id', $row['aid'])->find();
        $domainList = Db::name('cert_domain')->where('oid', $row['oid'])->column('domain');
        $typename = DeployHelper::$deploy_config[$account['type']]['name'];
        $mail_title = $typename;
        if(!empty($row['remark'])) $mail_title .= '('.$row['remark'].')';
        $mail_title .= '证书部署'.($result?'成功':'失败').'通知';
        if ($result) {
            $mail_content = '尊敬的用户，您好：您的SSL证书已成功部署到'.$typename.'！<br/>自动部署账户：['.$account['id'].']'.$typename.'('.($account['remark']?$account['remark']:$account['name']).')<br/>关联SSL证书：['.$row['oid'].']'.implode('、', $domainList).'<br/>任务备注：'.($row['remark']?$row['remark']:'无');
        } else {
            $mail_content = '尊敬的用户，您好：您的SSL证书部署失败！<br/>失败原因：'.$row['error'].'<br/>自动部署账户：['.$account['id'].']'.$typename.'('.($account['remark']?$account['remark']:$account['name']).')<br/>关联SSL证书：['.$row['oid'].']'.implode('、', $domainList).'<br/>任务备注：'.($row['remark']?$row['remark']:'无');
        }
        $mail_content .= '<br/>'.self::$sitename.'<br/>'.date('Y-m-d H:i:s');

        if (config_get('cert_notice_mail') == 1 || config_get('cert_notice_mail') == 2 && !$result) {
            $mail_name = config_get('mail_recv') ? config_get('mail_recv') : config_get('mail_name');
            self::send_mail($mail_name, $mail_title, $mail_content);
        }
        if (config_get('cert_notice_wxtpl') == 1 || config_get('cert_notice_wxtpl') == 2 && !$result) {
            $content = str_replace(['<br/>', '<b>', '</b>'], ["\n\n", '**', '**'], $mail_content);
            self::send_wechat_tplmsg($mail_title, $content);
        }
        if (config_get('cert_notice_tgbot') == 1 || config_get('cert_notice_tgbot') == 2 && !$result) {
            $content = str_replace('<br/>', "\n", $mail_content);
            $content = "<strong>" . $mail_title . "</strong>\n" . $content;
            self::send_telegram_bot($content);
        }
        Db::name('cert_deploy')->where('id', $id)->update(['issend' => 1]);
    }

    public static function send_mail($to, $sub, $msg)
    {
        $mail_type = config_get('mail_type');
        if ($mail_type == 1) {
            $mail = new \app\lib\mail\Sendcloud(config_get('mail_apiuser'), config_get('mail_apikey'));
            return $mail->send($to, $sub, $msg, config_get('mail_name'), self::$sitename);
        } elseif ($mail_type == 2) {
            $mail = new \app\lib\mail\Aliyun(config_get('mail_apiuser'), config_get('mail_apikey'));
            return $mail->send($to, $sub, $msg, config_get('mail_name'), self::$sitename);
        } else {
            $mail_name = config_get('mail_name');
            $mail_port = intval(config_get('mail_port'));
            $mail_smtp = config_get('mail_smtp');
            $mail_pwd = config_get('mail_pwd');
            if (!$mail_name || !$mail_port || !$mail_smtp || !$mail_pwd) return false;
            $mail = new \app\lib\mail\PHPMailer\PHPMailer(true);
            try {
                $mail->SMTPDebug = 0;
                $mail->CharSet = 'UTF-8';
                $mail->Timeout = 5;
                $mail->isSMTP();
                $mail->Host = $mail_smtp;
                $mail->SMTPAuth = true;
                $mail->Username = $mail_name;
                $mail->Password = $mail_pwd;
                if ($mail_port == 587) $mail->SMTPSecure = 'tls';
                else if ($mail_port >= 465) $mail->SMTPSecure = 'ssl';
                else $mail->SMTPAutoTLS = false;
                $mail->Port = $mail_port;
                $mail->setFrom($mail_name, self::$sitename);
                $mail->addAddress($to);
                $mail->addReplyTo($mail_name, self::$sitename);
                $mail->isHTML(true);
                $mail->Subject = $sub;
                $mail->Body = $msg;
                $mail->send();
                return true;
            } catch (\Exception $e) {
                return $mail->ErrorInfo;
            }
        }
    }

    public static function send_wechat_tplmsg($title, $content)
    {
        $wechat_apptoken = config_get('wechat_apptoken');
        $wechat_appuid = config_get('wechat_appuid');
        if (!$wechat_apptoken || !$wechat_appuid) return false;
        $url = 'https://wxpusher.zjiecode.com/api/send/message';
        $post = ['appToken' => $wechat_apptoken, 'content' => $content, 'summary' => $title, 'contentType' => 3, 'uids' => [$wechat_appuid]];
        $result = get_curl($url, json_encode($post), 0, 0, 0, 0, 0, ['Content-Type: application/json; charset=UTF-8']);
        $arr = json_decode($result, true);
        if (isset($arr['success']) && $arr['success'] == true) {
            return true;
        } else {
            return $arr['msg'];
        }
    }

    public static function send_telegram_bot($content)
    {
        $tgbot_token = config_get('tgbot_token');
        $tgbot_chatid = config_get('tgbot_chatid');
        if (!$tgbot_token || !$tgbot_chatid) return false;
        $url = 'https://api.telegram.org/bot'.$tgbot_token.'/sendMessage';
        $post = ['chat_id' => $tgbot_chatid, 'text' => $content, 'parse_mode' => 'HTML'];
        $result = self::telegram_curl($url, http_build_query($post));
        $arr = json_decode($result, true);
        if (isset($arr['ok']) && $arr['ok'] == true) {
            return true;
        } else {
            return $arr['description'];
        }
    }

    private static function telegram_curl($url, $post)
    {
        $ch = curl_init();
        if (config_get('tgbot_proxy') == 1) {
            $proxy_server = config_get('proxy_server');
            $proxy_port = intval(config_get('proxy_port'));
            $proxy_userpwd = config_get('proxy_user').':'.config_get('proxy_pwd');
            $proxy_type = config_get('proxy_type');
            if ($proxy_type == 'https') {
                $proxy_type = CURLPROXY_HTTPS;
            } elseif ($proxy_type == 'sock4') {
                $proxy_type = CURLPROXY_SOCKS4;
            } elseif ($proxy_type == 'sock5') {
                $proxy_type = CURLPROXY_SOCKS5;
            } else {
                $proxy_type = CURLPROXY_HTTP;
            }
            curl_setopt($ch, CURLOPT_PROXYAUTH, CURLAUTH_BASIC);
            curl_setopt($ch, CURLOPT_PROXY, $proxy_server);
            curl_setopt($ch, CURLOPT_PROXYPORT, $proxy_port);
            if ($proxy_userpwd != ':') {
                curl_setopt($ch, CURLOPT_PROXYUSERPWD, $proxy_userpwd);
            }
            curl_setopt($ch, CURLOPT_PROXYTYPE, $proxy_type);
        }
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        $httpheader[] = "Accept: */*";
        $httpheader[] = "Accept-Encoding: gzip,deflate,sdch";
        $httpheader[] = "Accept-Language: zh-CN,zh;q=0.8";
        $httpheader[] = "Connection: close";
        curl_setopt($ch, CURLOPT_HTTPHEADER, $httpheader);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
        curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/5.0 (Linux; U; Android 4.0.4; es-mx; HTC_One_X Build/IMM76D) AppleWebKit/534.30 (KHTML, like Gecko) Version/4.0");
        curl_setopt($ch, CURLOPT_ENCODING, "gzip");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        $ret = curl_exec($ch);
        curl_close($ch);
        return $ret;
    }
}
