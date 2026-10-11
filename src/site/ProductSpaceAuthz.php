<?php
declare(strict_types=1);
/** Product memberships never inherit the global Admin role. Global write constraints remain authoritative. */
final class ProductSpaceAuthz
{
    public const ROLES = ['owner','admin','editor','member','viewer'];
    public static function read(array $space): bool { return ($space['member_active']??false)===true; }
    public static function write(array $u,array $space): bool {
        return self::read($space) && $space['status']==='active' && Authz::canCommunityWrite($u)
            && ($u['acting']??false)!==true && in_array($space['role'],['owner','admin','editor','member'],true);
    }
    public static function manage(array $u,array $space): bool {
        return self::write($u,$space) && in_array($space['role'],['owner','admin'],true);
    }
    public static function owner(array $u,array $space): bool {
        return self::read($space) && $space['role']==='owner' && Authz::canCommunityWrite($u) && ($u['acting']??false)!==true;
    }
    public static function editHome(array $u,array $space): bool {return self::write($u,$space) && in_array($space['role'],['owner','admin','editor'],true);}
    public static function createItem(array $u,array $space,string $kind): bool {
        return self::write($u,$space) && ($kind!=='notice' || in_array($space['role'],['owner','admin','editor'],true));
    }
    public static function editItem(array $u,array $space,array $item): bool {
        return self::createItem($u,$space,$item['kind']) && ((int)$item['created_by']===(int)$u['id'] || in_array($space['role'],['owner','admin','editor'],true));
    }
    public static function changeMember(array $u,array $space,string $old,string $new): bool {
        if(!self::manage($u,$space) || $new==='owner')return false;
        return $space['role']==='owner' || (!in_array($old,['owner','admin'],true) && $new!=='admin');
    }
    public static function invite(array $u,array $space,string $role): bool {
        return self::manage($u,$space) && in_array($role,['admin','editor','member','viewer'],true) && ($role!=='admin'||$space['role']==='owner');
    }
}
