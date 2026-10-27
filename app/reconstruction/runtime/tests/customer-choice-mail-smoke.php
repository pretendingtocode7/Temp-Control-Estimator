<?php
/** Synthetic-only Send endpoint contract. No SMTP or provider implementation is loaded. */
declare(strict_types=1);
namespace {
 define('ABSPATH','/'); define('TC_ESTIMATE_REST_NS','tc-estimate/v1');
 class WP_Error { public function __construct(private string $code,private string $message,private array $data=[]){} public function get_error_code(){return $this->code;} public function get_error_message(){return $this->message;} public function get_error_data(){return $this->data;} }
 class WP_REST_Request {} class WP_REST_Response { public function __construct(public mixed $data,public int $status=200){} public function header($key,$value){} }
 function is_wp_error($v){return $v instanceof WP_Error;}
 function __($s,$domain=''){return $s;} function esc_html($s){return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');}
 function esc_url($s){return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');} function esc_url_raw($s){return (string)$s;}
 function sanitize_text_field($s){return trim(strip_tags((string)$s));} function sanitize_email($s){return trim((string)$s);} function is_email($s){return filter_var($s,FILTER_VALIDATE_EMAIL);}
 function wp_kses_post($s){return $s;} function get_option($key,$default=''){return $key==='tc_estimate_zoho_org_id'?'600000000000000001':$default;}
 function wp_delete_file($p){if(is_file($p))unlink($p);} function set_transient($key,$value,$ttl){$GLOBALS['jobs'][$key]=$value;return true;}
 function get_transient($key){return $GLOBALS['jobs'][$key]??false;}
 function wp_mail($to,$subject,$body,$headers,$attachments){$GLOBALS['mail'][]=compact('to','subject','body','headers','attachments');return $GLOBALS['mail_ok'];}
}
namespace TempControl\Estimate {
 class Security {public static function instance(){return new self;} public function sanitize_zoho_id($v){return preg_match('/^[0-9]{15,20}$/',$v)?$v:'';}}
 class Audit_Log {public static function instance(){return new self;} public function update($id,$value){$GLOBALS['audit'][]=[$id,$value];}}
 class Customer_Search {public static function instance(){return new self;} public function get_account($id){return $GLOBALS['customer'];}}
 class Customer_Choices {public static function instance(){return new self;} public function link_for_estimate($id,$owner){$GLOBALS['link_calls'][]=[$id,$owner];return $GLOBALS['link'];}}
 class Zoho_API {public const SERVICE_BOOKS='books'; public static function instance(){return new self;} public function get($service,$path,$query){$GLOBALS['gets'][]=[$service,$path,$query];return $GLOBALS['estimate'];} public function post($service,$path,$data){$GLOBALS['posts'][]=[$service,$path,$data];return $GLOBALS['mark_result'];}}
}
namespace {
 require dirname(__DIR__,4).'/endpoints/class-endpoint-base.php';
 require dirname(__DIR__).'/endpoints/class-send-estimate.php';
 $endpoint=\TempControl\Estimate\Endpoints\Send_Estimate::instance();
 $body_method=new \ReflectionMethod($endpoint,'branded_email_body');$body_method->setAccessible(true);
 $send_method=new \ReflectionMethod($endpoint,'send_job');$send_method->setAccessible(true);
 $cases=0;$assertions=0;
 function check($condition,$message){global $assertions;$assertions++;if(!$condition)throw new \RuntimeException($message);}
 function defaults(){
  $GLOBALS['jobs']=$GLOBALS['mail']=$GLOBALS['audit']=$GLOBALS['link_calls']=$GLOBALS['gets']=$GLOBALS['posts']=[];
  $GLOBALS['mail_ok']=true;$GLOBALS['mark_result']=['code'=>0];
  $GLOBALS['customer']=['name'=>'Synthetic Customer','email'=>'customer@example.test'];
  $GLOBALS['estimate']=['estimate'=>['estimate_id'=>'500000000000000001','estimate_number'=>'SYNTHETIC-001','status'=>'draft','estimate_url'=>'https://books.zoho.com/portal/synthetic/estimate/fixture']];
  $GLOBALS['link']=['available'=>true,'url'=>'https://example.test/?tc_estimate_choices=1#token=synthetic-link'];
 }
 function run_job($endpoint,$method){
  $path=sys_get_temp_dir().'/tc-choice-mail-fixture.pdf';file_put_contents($path,"%PDF-1.4\nSynthetic local test only\n");
  $job=['job_id'=>'synthetic-job','user_id'=>12,'estimate_id'=>'500000000000000001','account_id'=>'200000000000000001','audit_id'=>77,'attachment_path'=>$path,'proposal_html'=>'<h3>Comparison options</h3>'];
  $method->invoke($endpoint,$job);
  return $GLOBALS['jobs']['tc_estimate_send_job_'.md5('synthetic-job')]??null;
 }
 defaults();$legacy=$body_method->invoke($endpoint,'Synthetic <Customer>','https://books.zoho.com/portal/synthetic/estimate/fixture','<p>Proposal</p>');
 check(str_contains($legacy,'Review and approve estimate'),'Legacy approval label retained.');check(!str_contains($legacy,'Choose packages and update estimate'),'Legacy link is not mislabeled.');check(str_contains($legacy,'Synthetic &lt;Customer&gt;'),'Customer text escaped.');$cases++;
 $choices=$body_method->invoke($endpoint,'Synthetic Customer',$GLOBALS['link']['url'],'<p>Comparison</p>',true);
 check(str_contains($choices,'Choose packages and update estimate'),'Choice action explicit.');check(str_contains($choices,'one package for each system'),'Physical-system scope explicit.');check(str_contains($choices,'existing estimate in Zoho Books will be updated'),'Existing quote update explained.');check(str_contains($choices,'approve the final selection there'),'Approval stays separate.');check(str_contains($choices,'attached comparison stays as originally prepared'),'Historical comparison PDF is distinguished from the updated final quote.');check(!str_contains($choices,'>Review and approve estimate</a>'),'Native approval not offered before choices.');$cases++;
 defaults();$job=run_job($endpoint,$send_method);
 check(count($GLOBALS['mail'])===1,'One mock email only.');check($GLOBALS['mail'][0]['to']==='customer@example.test','Only synthetic recipient.');check(str_contains($GLOBALS['mail'][0]['body'],$GLOBALS['link']['url']),'Scoped choice link included.');check(!str_contains($GLOBALS['mail'][0]['body'],'/portal/synthetic/estimate/fixture'),'Old approval URL not mixed into choice email.');check($GLOBALS['link_calls']===[['500000000000000001',12]],'Cron job passes original owner and bound estimate.');check($job['sent']===true,'Successful mail recorded.');check(count($GLOBALS['posts'])===1&&str_contains($GLOBALS['posts'][0][1],'/status/sent?'),'Only existing sent-status followup.');$cases++;
 defaults();$GLOBALS['link']=new WP_Error('tc_estimate_choice_unavailable','Synthetic unavailable');$job=run_job($endpoint,$send_method);
 check(!$GLOBALS['mail']&&!$GLOBALS['posts'],'Link error blocks email/status write.');check($job['status']==='error'&&$job['sent']===false,'Link failure is explicit.');$cases++;
 defaults();$GLOBALS['link']=['available'=>true,'url'=>''];$job=run_job($endpoint,$send_method);
 check(!$GLOBALS['mail']&&!$GLOBALS['posts'],'Missing choice URL never falls back to approval.');check($job['error_code']==='tc_estimate_choice_link_missing','Missing link error preserved.');$cases++;
 defaults();$GLOBALS['link']=['available'=>false];$job=run_job($endpoint,$send_method);
 check(count($GLOBALS['mail'])===1,'Legacy mock email preserved.');check(str_contains($GLOBALS['mail'][0]['body'],'Review and approve estimate'),'Legacy approval workflow preserved.');check(!str_contains($GLOBALS['mail'][0]['body'],'Choose packages'),'No phantom alternatives for legacy quote.');$cases++;
 foreach(['sent','accepted','declined','invoiced']as$status){defaults();$GLOBALS['estimate']['estimate']['status']=$status;$job=run_job($endpoint,$send_method);check(!$GLOBALS['mail']&&!$GLOBALS['link_calls']&&!$GLOBALS['posts'],'Already-sent/final quote never re-emails or mints link.');check($job['sent']===true,'Existing already-sent status retained.');$cases++;}
 defaults();$GLOBALS['customer']['email']='not-an-email';$job=run_job($endpoint,$send_method);check(!$GLOBALS['mail']&&!$GLOBALS['link_calls'],'Invalid recipient blocks link/mail.');$cases++;
 defaults();$GLOBALS['estimate']=new WP_Error('synthetic_provider_error','Synthetic unavailable');$job=run_job($endpoint,$send_method);check(!$GLOBALS['mail']&&!$GLOBALS['link_calls'],'Read failure blocks link/mail.');$cases++;
 defaults();$GLOBALS['mail_ok']=false;$job=run_job($endpoint,$send_method);check(count($GLOBALS['mail'])===1&&!$GLOBALS['posts'],'Known mail failure never marks Books sent.');check($job['sent']===false&&$job['status']==='error','Mail failure explicit.');$cases++;
 defaults();$GLOBALS['mark_result']=new WP_Error('synthetic_status_timeout','Synthetic timeout');$job=run_job($endpoint,$send_method);check(count($GLOBALS['mail'])===1&&$job['sent']===true,'Delivered mock email is never retried after status uncertainty.');$cases++;
 echo "Customer choice mail: $cases cases passed, $assertions assertions passed; no real mail/provider implementation loaded.\n";
}
