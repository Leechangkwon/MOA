<?php
declare(strict_types=1);
require_once __DIR__.'/ProductSpaceAuthz.php';
final class ProductSpaceError extends RuntimeException {
    public function __construct(public readonly int $status,public readonly string $reason='') {parent::__construct($reason);}
}
/** All statements bind values. Locks follow space -> member/invite/item/dashboard, never reverse. */
final class ProductSpaces
{
    public const KINDS=['notice','task','event','file','story'];
    public function __construct(private readonly array $u) {}
    private function db(): Db {return Db::instance();}
    private function fail(int $status,string $reason=''): never {throw new ProductSpaceError($status,$reason);}
    private function uid(): int {return (int)$this->u['id'];}
    private function mutation(): void {if(!Authz::canCommunityWrite($this->u)||($this->u['acting']??false)===true)$this->fail(403);}
    private function audit(string $action,string $id,array $detail=[]): void {Audit::allow('product.'.$action,['target_type'=>'product_space','target_name'=>$id,'detail'=>$detail]);}
    private function atomic(callable $work): mixed {
        $db=$this->db();$db->begin();try{$result=$work();$db->commit();return $result;}catch(Throwable $e){$db->rollback();throw $e;}
    }
    public static function uuid(mixed $id): bool {return is_string($id)&&preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',$id)===1;}
    private function text(mixed $v,int $max,bool $empty=false): string {
        if(!is_string($v)||iconv_strlen($v,'UTF-8')===false||iconv_strlen($v,'UTF-8')>$max||(!$empty&&trim($v)==='')||str_contains($v,"\0"))$this->fail(400);
        return $empty?$v:trim($v);
    }
    private function exact(array $b,array $allowed): void {if(array_diff(array_keys($b),$allowed)!==[])$this->fail(400);}
    private function id(mixed $id): string {if(!self::uuid($id))$this->fail(404);return strtolower($id);}
    private function space(string $id,bool $lock=false): array {
        $id=$this->id($id);
        if($lock){$row=$this->db()->run('SELECT id FROM product_spaces WHERE id=:id FOR UPDATE',
                [':id'=>$id])->fetch();if(!$row)$this->fail(404);}
        $row=$this->db()->run('SELECT s.*,m.role,m.active AS member_active FROM product_spaces s JOIN product_space_members m ON m.space_id=s.id AND m.user_id=:uid AND m.active=true WHERE s.id=:id',
                [':uid'=>$this->uid(),':id'=>$id])->fetch();
        if(!$row||!ProductSpaceAuthz::read($row))$this->fail(404);return $row;
    }
    public function outSpace(array $s): array {
        return ['id'=>$s['id'],'name'=>$s['name'],'kind'=>$s['kind'],'status'=>$s['status'],'role'=>$s['role'],'created_at'=>$s['created_at'],'can_manage'=>ProductSpaceAuthz::manage($this->u,$s),'can_edit_home'=>ProductSpaceAuthz::editHome($this->u,$s),'can_write'=>ProductSpaceAuthz::write($this->u,$s),'can_restore'=>ProductSpaceAuthz::owner($this->u,$s),'can_transfer'=>ProductSpaceAuthz::owner($this->u,$s)];
    }
    public function spaces(): array {
        $rows=$this->db()->run('SELECT s.*,m.role,m.active AS member_active FROM product_spaces s JOIN product_space_members m ON m.space_id=s.id AND m.user_id=:uid AND m.active=true ORDER BY s.updated_at DESC,s.id LIMIT 100',
                [':uid'=>$this->uid()])->fetchAll();
        return ['can_create'=>Authz::canCommunityWrite($this->u)&&($this->u['acting']??false)!==true,'spaces'=>array_map(fn($s)=>$this->outSpace($s),$rows)];
    }
    public function create(array $b): array {
        $this->mutation();$this->exact($b,['name','kind']);$name=$this->text($b['name']??null,100);$kind=$b['kind']??null;if(!in_array($kind,['company','group'],true))$this->fail(400);
        return $this->atomic(function()use($name,$kind){
            // Serialize per creator to enforce limit without affecting other accounts.
            $this->db()->run('SELECT id FROM users WHERE id=:uid FOR UPDATE',
                [':uid'=>$this->uid()]);
            $count=(int)$this->db()->run('SELECT count(*) FROM product_spaces WHERE created_by=:uid',
                [':uid'=>$this->uid()])->fetchColumn();if($count>=50)$this->fail(429);
            $s=$this->db()->run('INSERT INTO product_spaces(name,kind,created_by) VALUES(:name,:kind,:uid) RETURNING *',
                [':name'=>$name,':kind'=>$kind,':uid'=>$this->uid()])->fetch();
            $this->db()->run('INSERT INTO product_space_members(space_id,user_id,role) VALUES(:sid,:uid,\'owner\')',
                [':sid'=>$s['id'],':uid'=>$this->uid()]);
            $this->audit('space.create',$s['id']);return ['space'=>$this->outSpace($this->space($s['id']))];
        });
    }
    public function get(string $id): array {return ['space'=>$this->outSpace($this->space($id))];}
    public function update(string $id,array $b): array {
        $this->mutation();$this->exact($b,['name','status']);if(!$b)$this->fail(400);
        return $this->atomic(function()use($id,$b){$s=$this->space($id,true);
            if(isset($b['status'])){if(!in_array($b['status'],['active','archived'],true))$this->fail(400);if(!ProductSpaceAuthz::owner($this->u,$s))$this->fail(403);}
            else if(!ProductSpaceAuthz::manage($this->u,$s))$this->fail(403);
            if(isset($b['name'])&&$s['status']==='archived')$this->fail(403);
            $name=isset($b['name'])?$this->text($b['name'],100):$s['name'];$status=$b['status']??$s['status'];
            $this->db()->run('UPDATE product_spaces SET name=:name,status=:status,updated_at=now() WHERE id=:id',
                [':name'=>$name,':status'=>$status,':id'=>$id]);
            $this->audit('space.update',$id);return $this->get($id);
        });
    }
    public function members(string $id): array {
        $s=$this->space($id);$members=$this->db()->run('SELECT m.user_id,m.role,u.username,u.display_name FROM product_space_members m JOIN users u ON u.id=m.user_id WHERE m.space_id=:sid AND m.active=true AND u.is_active=true ORDER BY m.joined_at,m.user_id LIMIT 500',
                [':sid'=>$id])->fetchAll();
        return ['members'=>$members,'me'=>$this->uid(),'space'=>$this->outSpace($s)];
    }
    public function memberChange(string $id,int $uid,?array $b): array {
        $this->mutation();if($b!==null)$this->exact($b,['role']);
        return $this->atomic(function()use($id,$uid,$b){$s=$this->space($id,true);
            $m=$this->db()->run('SELECT * FROM product_space_members WHERE space_id=:sid AND user_id=:uid AND active=true',
                [':sid'=>$id,':uid'=>$uid])->fetch();if(!$m)$this->fail(404);
            $role=$b['role']??null;
            if($b!==null){if(!in_array($role,ProductSpaceAuthz::ROLES,true))$this->fail(400);if(!ProductSpaceAuthz::changeMember($this->u,$s,$m['role'],$role))$this->fail(403);}
            else {if($uid!==$this->uid()&&!ProductSpaceAuthz::manage($this->u,$s)&&!ProductSpaceAuthz::owner($this->u,$s))$this->fail(403);
                if($uid!==$this->uid()&&$m['role']==='owner'&&$s['role']!=='owner')$this->fail(403);
                if($uid!==$this->uid()&&$m['role']==='admin'&&$s['role']==='admin')$this->fail(403);
            }
            if($m['role']==='owner'&&($b===null||$role!=='owner')){
                $owners=(int)$this->db()->run('SELECT count(*) FROM product_space_members WHERE space_id=:sid AND active=true AND role=\'owner\'',
                [':sid'=>$id])->fetchColumn();if($owners<=1)$this->fail(409,'last_owner');
            }
            if($b===null)$this->db()->run('UPDATE product_space_members SET active=false WHERE space_id=:sid AND user_id=:uid',
                [':sid'=>$id,':uid'=>$uid]);
            else $this->db()->run('UPDATE product_space_members SET role=:role WHERE space_id=:sid AND user_id=:uid',
                [':role'=>$role,':sid'=>$id,':uid'=>$uid]);
            $this->audit('member.change',$id,['member_id'=>$uid,'role_before'=>$m['role'],'role_after'=>$b===null?'removed':$role]);return ['ok'=>true];
        });
    }
    public function transfer(string $id,array $b): array {
        $this->mutation();$this->exact($b,['user_id','password','otp']);if(!is_int($b['user_id']??null)||!is_string($b['password']??null))$this->fail(400);
        // Existing password reauth applies to this authenticated session; password never stored/audited.
        $s=$this->space($id);if(!ProductSpaceAuthz::owner($this->u,$s)||$s['status']!=='active')$this->fail(403);
        if(strlen($b['password'])>Auth::MAX_PASSWORD_BYTES)$this->fail(400);
        $verified=Auth::verifyCurrentPassword($this->u,$b['password']);
        if($verified&&($this->u['mfa_enabled']??false)===true)$verified=is_string($b['otp']??null)&&preg_match('/^[0-9]{6}$/',$b['otp'])===1&&Auth::verifyOtpOnce($this->u,$b['otp'])==='ok';
        if(!$verified){Auth::countFailureAndRevoke($this->uid());Audit::deny('product.transfer','bad_credentials',['target_type'=>'product_space','target_name'=>$id]);$this->fail(403);}
        [$ok]=Auth::reauth($this->u,$b['password']);if(!$ok)$this->fail(403);unset($b['password'],$b['otp']);
        return $this->atomic(function()use($id,$b){$s=$this->space($id,true);if(!ProductSpaceAuthz::owner($this->u,$s)||$s['status']!=='active')$this->fail(403);
            $target=$this->db()->run('SELECT u.* FROM product_space_members m JOIN users u ON u.id=m.user_id WHERE m.space_id=:sid AND m.user_id=:uid AND m.active=true AND u.is_active=true',
                [':sid'=>$id,':uid'=>$b['user_id']])->fetch();if(!$target||!Authz::canCommunityWrite($target)||$b['user_id']===$this->uid())$this->fail(400);
            $this->db()->run('UPDATE product_space_members SET role=\'owner\' WHERE space_id=:sid AND user_id=:uid',
                [':sid'=>$id,':uid'=>$b['user_id']]);
            $this->db()->run('UPDATE product_space_members SET role=\'admin\' WHERE space_id=:sid AND user_id=:uid',
                [':sid'=>$id,':uid'=>$this->uid()]);
            $this->audit('owner.transfer',$id,['from_user'=>$this->uid(),'to_user'=>$b['user_id'],'role_before'=>'owner','role_after'=>'admin']);return $this->get($id);
        });
    }
    public function invites(string $id): array {
        $s=$this->space($id);if(!ProductSpaceAuthz::manage($this->u,$s))$this->fail(403);
        return ['invites'=>$this->db()->run('SELECT id,role,expires_at,max_uses,uses,revoked_at,created_at FROM product_space_invites WHERE space_id=:sid ORDER BY created_at DESC LIMIT 100',
                [':sid'=>$id])->fetchAll()];
    }
    public function inviteCreate(string $id,array $b): array {
        $this->mutation();$this->exact($b,['role','expires_hours','max_uses']);$role=$b['role']??'member';$hours=$b['expires_hours']??72;$max=$b['max_uses']??1;
        if(!is_int($hours)||$hours<1||$hours>168||!is_int($max)||$max<1||$max>100)$this->fail(400);
        return $this->atomic(function()use($id,$role,$hours,$max){$s=$this->space($id,true);if(!ProductSpaceAuthz::invite($this->u,$s,$role))$this->fail(403);
            $count=(int)$this->db()->run('SELECT count(*) FROM product_space_invites WHERE space_id=:sid AND revoked_at IS NULL AND expires_at>now() AND uses<max_uses',
                [':sid'=>$id])->fetchColumn();if($count>=100)$this->fail(429);
            $token=bin2hex(random_bytes(32));$hash=hash('sha256',$token);
            $invite=$this->db()->run('INSERT INTO product_space_invites(space_id,token_hash,role,expires_at,max_uses,created_by) VALUES(:sid,:hash,:role,now()+CAST(:hours AS integer)*interval \'1 hour\',:max,:uid) RETURNING id,role,expires_at,max_uses,uses',
                [':sid'=>$id,':hash'=>$hash,':role'=>$role,':hours'=>$hours,':max'=>$max,':uid'=>$this->uid()])->fetch();
            $this->audit('invite.create',$id,['record_id'=>$invite['id'],'role_after'=>$role]);return ['invite'=>$invite,'token'=>$token];
        });
    }
    public function inviteRevoke(string $id,string $inviteId): array {
        $this->mutation();$inviteId=$this->id($inviteId);return $this->atomic(function()use($id,$inviteId){$s=$this->space($id,true);if(!ProductSpaceAuthz::manage($this->u,$s))$this->fail(403);
            $row=$this->db()->run('UPDATE product_space_invites SET revoked_at=COALESCE(revoked_at,now()) WHERE space_id=:sid AND id=:id RETURNING id',
                [':sid'=>$id,':id'=>$inviteId])->fetch();if(!$row)$this->fail(404);$this->audit('invite.revoke',$id,['record_id'=>$inviteId]);return ['ok'=>true];
        });
    }
    public function join(array $b): array {
        $this->mutation();$this->exact($b,['token']);$token=$b['token']??null;if(!is_string($token)||!preg_match('/^[a-f0-9]{64}$/',$token))$this->fail(404);
        $hash=hash('sha256',$token);unset($token,$b);
        return $this->atomic(function()use($hash){
            // Initial lookup reveals nothing; lock ordering remains space before invite.
            $found=$this->db()->run('SELECT space_id FROM product_space_invites WHERE token_hash=:hash',
                [':hash'=>$hash])->fetch();if(!$found)$this->fail(404);
            $s=$this->db()->run('SELECT * FROM product_spaces WHERE id=:id FOR UPDATE',
                [':id'=>$found['space_id']])->fetch();
            $invite=$this->db()->run('SELECT * FROM product_space_invites WHERE token_hash=:hash AND revoked_at IS NULL AND expires_at>now() AND uses<max_uses FOR UPDATE',
                [':hash'=>$hash])->fetch();if(!$invite||!$s||$s['status']!=='active')$this->fail(404);
            $issuer=$this->db()->run('SELECT u.*,m.role AS space_role,m.active AS member_active FROM users u JOIN product_space_members m ON m.user_id=u.id AND m.space_id=:sid AND m.active=true WHERE u.id=:uid AND u.is_active=true',
                [':sid'=>$s['id'],':uid'=>$invite['created_by']])->fetch();
            if(!$issuer)$this->fail(404);$issuerSpace=$s+['role'=>$issuer['space_role'],'member_active'=>$issuer['member_active']];if(!ProductSpaceAuthz::invite($issuer,$issuerSpace,$invite['role']))$this->fail(404);
            $existing=$this->db()->run('SELECT active FROM product_space_members WHERE space_id=:sid AND user_id=:uid',
                [':sid'=>$s['id'],':uid'=>$this->uid()])->fetch();
            if($existing&&$existing['active']===true)return $this->get($s['id']);
            $count=(int)$this->db()->run('SELECT count(*) FROM product_space_members WHERE space_id=:sid AND active=true',
                [':sid'=>$s['id']])->fetchColumn();if($count>=500)$this->fail(429);
            $this->db()->run('INSERT INTO product_space_members(space_id,user_id,role) VALUES(:sid,:uid,:role) ON CONFLICT(space_id,user_id) DO UPDATE SET role=EXCLUDED.role,active=true,joined_at=now()',
                [':sid'=>$s['id'],':uid'=>$this->uid(),':role'=>$invite['role']]);
            $this->db()->run('UPDATE product_space_invites SET uses=uses+1 WHERE id=:id',
                [':id'=>$invite['id']]);$this->audit('member.join',$s['id'],['member_id'=>$this->uid(),'role_after'=>$invite['role']]);return $this->get($s['id']);
        });
    }
    public static function defaultLayout(bool $personal=false,string $kind='company'): array {
        $ids=$personal?['recent','services','tools','stories']:($kind==='group'?['event','task','notice','story','file']:['notice','task','file','event','story']);
        return ['version'=>1,'widgets'=>array_map(static fn($id)=>['id'=>$id,'visible'=>true,'size'=>'wide'],$ids),'source'=>'all','view'=>'recent'];
    }
    private function layout(mixed $value,bool $personal): array {
        if(!is_array($value)||strlen(json_encode($value,JSON_THROW_ON_ERROR))>4096)$this->fail(400);
        $this->exact($value,['version','widgets','source','view']);$ids=$personal?['recent','services','tools','stories']:self::KINDS;
        if(($value['version']??null)!==1||!isset($value['widgets'])||!is_array($value['widgets'])||!array_is_list($value['widgets'])||count($value['widgets'])!==count($ids)||!in_array($value['source']??null,['all','files','pages'],true)||!in_array($value['view']??null,['recent','favorites'],true))$this->fail(400);
        $seen=[];foreach($value['widgets'] as $w){if(!is_array($w))$this->fail(400);$this->exact($w,['id','visible','size']);if(!in_array($w['id']??null,$ids,true)||isset($seen[$w['id']])||!is_bool($w['visible']??null)||!in_array($w['size']??null,['wide','small'],true))$this->fail(400);$seen[$w['id']]=true;}
        return $value;
    }
    public function dashboard(?string $id): array {
        $space=$id!==null?$this->space($id):null;
        $row=$id===null?$this->db()->run('SELECT layout,revision FROM product_personal_dashboards WHERE user_id=:uid',
                [':uid'=>$this->uid()])->fetch():$this->db()->run('SELECT layout,revision FROM product_space_dashboards WHERE space_id=:sid',
                [':sid'=>$id])->fetch();
        return ['layout'=>$row?json_decode($row['layout'],true,512,JSON_THROW_ON_ERROR):self::defaultLayout($id===null,$space['kind']??'company'),'revision'=>$row?(int)$row['revision']:0];
    }
    public function dashboardSave(?string $id,array $b): array {
        $this->exact($b,['layout','revision']);if(!is_int($b['revision']??null)||$b['revision']<0)$this->fail(400);$layout=$this->layout($b['layout']??null,$id===null);
        if(($this->u['acting']??false)===true)$this->fail(403);
        return $this->atomic(function()use($id,$b,$layout){
            if($id!==null){$s=$this->space($id,true);if(!ProductSpaceAuthz::editHome($this->u,$s))$this->fail(403);}
            else $this->db()->run('SELECT id FROM users WHERE id=:uid FOR UPDATE',
                [':uid'=>$this->uid()]);
            $current=$this->dashboard($id);if($current['revision']!==$b['revision'])$this->fail(409,'revision_conflict');$json=json_encode($layout,JSON_THROW_ON_ERROR);
            if($id===null)$this->db()->run('INSERT INTO product_personal_dashboards(user_id,layout) VALUES(:uid,CAST(:layout AS jsonb)) ON CONFLICT(user_id) DO UPDATE SET layout=EXCLUDED.layout,revision=product_personal_dashboards.revision+1,updated_at=now()',
                [':uid'=>$this->uid(),':layout'=>$json]);
            else $this->db()->run('INSERT INTO product_space_dashboards(space_id,layout,updated_by) VALUES(:sid,CAST(:layout AS jsonb),:uid) ON CONFLICT(space_id) DO UPDATE SET layout=EXCLUDED.layout,revision=product_space_dashboards.revision+1,updated_by=EXCLUDED.updated_by,updated_at=now()',
                [':sid'=>$id,':layout'=>$json,':uid'=>$this->uid()]);
            $this->audit('dashboard.save',$id??(string)$this->uid());return $this->dashboard($id);
        });
    }
    private function date(mixed $v): ?string {
        if($v===null||$v==='')return null;if(!is_string($v)||strlen($v)>40||!preg_match('/^\d{4}-\d{2}-\d{2}(T\d{2}:\d{2}(:\d{2})?(Z|[+-]\d{2}:\d{2})?)?$/',$v))$this->fail(400);
        try{$d=new DateTimeImmutable($v,new DateTimeZone('Asia/Seoul'));}catch(Throwable){$this->fail(400);}
        $errors=DateTimeImmutable::getLastErrors();if($errors&&($errors['warning_count']||$errors['error_count']))$this->fail(400);return $d->format(DateTimeInterface::ATOM);
    }
    private function validateItem(array $b,?array $old=null): array {
        $this->exact($b,['kind','title','body','status','assignee_id','due_at','starts_at','ends_at','file_id','revision']);
        $item=$old?array_intersect_key($old,array_flip(['kind','title','body','status','assignee_id','due_at','starts_at','ends_at','file_id'])):['body'=>'','status'=>'todo','assignee_id'=>null,'due_at'=>null,'starts_at'=>null,'ends_at'=>null,'file_id'=>null];
        foreach($b as $key=>$value)if($key!=='revision')$item[$key]=$value;
        if(!in_array($item['kind']??null,self::KINDS,true)||($old&&$item['kind']!==$old['kind']))$this->fail(400);
        $item['title']=$this->text($item['title']??null,200);$item['body']=$this->text($item['body'],20000,true);
        if(!in_array($item['status'],['todo','doing','done'],true))$this->fail(400);
        foreach(['due_at','starts_at','ends_at'] as $key)$item[$key]=$this->date($item[$key]);
        if($item['ends_at']!==null&&($item['starts_at']===null||strtotime($item['ends_at'])<strtotime($item['starts_at'])))$this->fail(400);
        if($item['kind']==='event'&&$item['starts_at']===null)$this->fail(400);
        if($item['assignee_id']!==null&&(!is_int($item['assignee_id'])||$item['kind']!=='task'))$this->fail(400);
        if($item['kind']==='file'){if(!self::uuid($item['file_id']))$this->fail(400);}
        else if($item['file_id']!==null)$this->fail(400);
        return $item;
    }
    private function itemReferences(string $sid,array $item): void {
        if($item['assignee_id']!==null){$person=$this->db()->run('SELECT u.* FROM product_space_members m JOIN users u ON u.id=m.user_id WHERE m.space_id=:sid AND m.user_id=:uid AND m.active=true AND m.role<>\'viewer\' AND u.is_active=true',
                [':sid'=>$sid,':uid'=>$item['assignee_id']])->fetch();if(!$person||!Authz::canCommunityWrite($person))$this->fail(400);}
        if($item['kind']==='file'){$file=$this->db()->run('SELECT files.*,\'file\' AS item_kind FROM files WHERE public_id=:id AND deleted_at IS NULL',
                [':id'=>$item['file_id']])->fetch();if(!$file)$this->fail(404);[$ok]=Authz::decideRead($this->u,$file);if(!$ok)$this->fail(404);}
    }
    private function visibleItemRows(?string $sid,?string $kind=null,?string $itemId=null,int $limit=100): array {
        // Authz provides only a trusted SQL predicate; every UUID/user/kind is still bound.
        [$fileWhere,$fileParams]=Authz::visibleFilesWhere($this->u);
        $scope=$sid===null?'s.status=\'active\'':'i.space_id=:sid';
        $kindWhere=$kind===null?'TRUE':'i.kind=:kind';$itemWhere=$itemId===null?'TRUE':'i.id=:iid';
        $sql='SELECT i.*,m.role,m.active AS member_active,s.status AS space_status,s.name AS space_name FROM product_space_items i JOIN product_spaces s ON s.id=i.space_id JOIN product_space_members m ON m.space_id=i.space_id AND m.user_id=:member_user AND m.active=true LEFT JOIN files f ON f.public_id=i.file_id WHERE i.deleted_at IS NULL AND (i.kind<>\'file\' OR (f.public_id IS NOT NULL AND (';
        $sql.=$fileWhere;
        $sql.='))) AND '.$scope.' AND '.$kindWhere.' AND '.$itemWhere.' ORDER BY i.updated_at DESC,i.id LIMIT :item_limit';
        $params=$fileParams+[':member_user'=>$this->uid(),':item_limit'=>$limit];if($sid!==null)$params[':sid']=$sid;if($kind!==null)$params[':kind']=$kind;if($itemId!==null)$params[':iid']=$itemId;
        return $this->db()->run($sql,$params)->fetchAll();
    }
    private function outItem(array $i): array {
        $space=['member_active'=>$i['member_active'],'role'=>$i['role'],'status'=>$i['space_status']];$edit=ProductSpaceAuthz::editItem($this->u,$space,$i);
        return ['id'=>$i['id'],'space_id'=>$i['space_id'],'space_name'=>$i['space_name'],'kind'=>$i['kind'],'title'=>$i['title'],'body'=>$i['body'],'status'=>$i['status'],'assignee_id'=>$i['assignee_id']===null?null:(int)$i['assignee_id'],'due_at'=>$i['due_at']===null?null:(new DateTimeImmutable($i['due_at']))->format(DateTimeInterface::ATOM),'starts_at'=>$i['starts_at']===null?null:(new DateTimeImmutable($i['starts_at']))->format(DateTimeInterface::ATOM),'ends_at'=>$i['ends_at']===null?null:(new DateTimeImmutable($i['ends_at']))->format(DateTimeInterface::ATOM),'file_id'=>$i['file_id'],'revision'=>(int)$i['revision'],'created_by'=>(int)$i['created_by'],'created_at'=>$i['created_at'],'updated_at'=>$i['updated_at'],'can_edit'=>$edit,'can_delete'=>$edit,'download_url'=>$i['kind']==='file'?Http::url('/api/product-spaces/'.$i['space_id'].'/items/'.$i['id'].'/download'):null];
    }
    public function items(string $id,?string $kind=null): array {
        $this->space($id);if($kind!==null&&!in_array($kind,self::KINDS,true))$this->fail(400);
        $rows=$this->visibleItemRows($id,$kind,null,101);return ['items'=>array_map(fn($i)=>$this->outItem($i),array_slice($rows,0,100)),'truncated'=>count($rows)>100];
    }
    public function item(string $id,string $iid): array {
        $this->space($id);$iid=$this->id($iid);$rows=$this->visibleItemRows($id,null,$iid,1);if(!$rows)$this->fail(404);return ['item'=>$this->outItem($rows[0])];
    }
    public function itemCreate(string $id,array $b): array {
        $this->mutation();if(array_key_exists('revision',$b))$this->fail(400);$item=$this->validateItem($b);
        return $this->atomic(function()use($id,$item){$s=$this->space($id,true);if(!ProductSpaceAuthz::createItem($this->u,$s,$item['kind']))$this->fail(403);$this->itemReferences($id,$item);
            $count=(int)$this->db()->run('SELECT count(*) FROM product_space_items WHERE space_id=:sid AND deleted_at IS NULL',
                [':sid'=>$id])->fetchColumn();if($count>=10000)$this->fail(429);
            $params=[':sid'=>$id,':uid'=>$this->uid()];foreach($item as $key=>$value)$params[':'.$key]=$value;
            $row=$this->db()->run('INSERT INTO product_space_items(space_id,kind,title,body,status,assignee_id,due_at,starts_at,ends_at,file_id,created_by) VALUES(:sid,:kind,:title,:body,:status,:assignee_id,:due_at,:starts_at,:ends_at,:file_id,:uid) RETURNING id',
                $params)->fetch();
            $this->audit('item.create',$id,['record_id'=>$row['id']]);return $this->item($id,$row['id']);
        });
    }
    public function itemChange(string $id,string $iid,?array $b): array {
        $this->mutation();$iid=$this->id($iid);
        return $this->atomic(function()use($id,$iid,$b){$s=$this->space($id,true);$old=$this->item($id,$iid)['item'];if(!ProductSpaceAuthz::editItem($this->u,$s,$old))$this->fail(403);
            if($b===null){$this->db()->run('UPDATE product_space_items SET deleted_at=now(),updated_at=now(),revision=revision+1 WHERE id=:iid AND space_id=:sid',
                [':iid'=>$iid,':sid'=>$id]);$this->audit('item.delete',$id,['record_id'=>$iid]);return ['ok'=>true];}
            if(!is_int($b['revision']??null)||$b['revision']!==$old['revision'])$this->fail(409,'revision_conflict');$item=$this->validateItem($b,$old);$this->itemReferences($id,$item);
            $params=[':iid'=>$iid,':sid'=>$id];foreach($item as $key=>$value)$params[':'.$key]=$value;
            $this->db()->run('UPDATE product_space_items SET title=:title,body=:body,status=:status,assignee_id=:assignee_id,due_at=:due_at,starts_at=:starts_at,ends_at=:ends_at,file_id=:file_id,kind=:kind,revision=revision+1,updated_at=now() WHERE id=:iid AND space_id=:sid',
                $params);
            $this->audit('item.update',$id,['record_id'=>$iid]);return $this->item($id,$iid);
        });
    }
    public function home(?string $id): array {
        $space=$id===null?[]:$this->get($id);
        $widgets=[];$truncated=[];foreach(self::KINDS as $kind){$rows=$this->visibleItemRows($id,$kind,null,7);$widgets[$kind]=array_map(fn($i)=>$this->outItem($i),array_slice($rows,0,6));$truncated[$kind]=count($rows)>6;}
        return $space+$this->dashboard($id)+['widgets'=>$widgets,'truncated'=>$truncated];
    }
    public function downloadFile(string $id,string $iid): string {
        $i=$this->item($id,$iid)['item'];if($i['kind']!=='file'||!is_string($i['file_id']))$this->fail(404);return $i['file_id'];
    }
}
