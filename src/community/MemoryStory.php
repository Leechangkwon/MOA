<?php
declare(strict_types=1);

/** Plain text and current attachment IDs only. No HTML or provider URLs. */
final class MemoryStory
{
    private static function fields(array $value, array $keys): void
    {
        $actual=array_keys($value); sort($actual); sort($keys);
        if ($actual !== $keys) throw new InvalidArgumentException('story_fields');
    }
    private static function text($value, int $limit, bool $empty=false): string
    {
        if (!is_string($value) || preg_match('//u',$value)!==1 || iconv_strlen($value,'UTF-8')>$limit
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/',$value) || (!$empty && trim($value)==='')) {
            throw new InvalidArgumentException('story_text');
        }
        return trim($value);
    }
    public static function validate($value, array $media): ?array
    {
        if ($value===null) return null;
        if (!is_array($value)) throw new InvalidArgumentException('story_object');
        self::fields($value,['title','intro','scenes']);
        $out=['title'=>self::text($value['title'],80),'intro'=>self::text($value['intro'],300,true),'scenes'=>[]];
        if (!is_array($value['scenes']) || !array_is_list($value['scenes']) || count($value['scenes'])<1 || count($value['scenes'])>10) throw new InvalidArgumentException('story_scenes');
        $seen=[];$chars=iconv_strlen($out['title'].$out['intro'],'UTF-8');
        foreach ($value['scenes'] as $scene) {
            if (!is_array($scene)) throw new InvalidArgumentException('story_scene');
            self::fields($scene,['media_ids','title','text']);
            $ids=$scene['media_ids'];
            if (!is_array($ids) || !array_is_list($ids) || !$ids || count($ids)>10) throw new InvalidArgumentException('story_photos');
            foreach($ids as $id) {
                if (!is_string($id) || !in_array($id,$media,true) || in_array($id,$seen,true)) throw new InvalidArgumentException('story_reference');
                $seen[]=$id;
            }
            $out['scenes'][]=['media_ids'=>$ids,'title'=>self::text($scene['title'],60),'text'=>self::text($scene['text'],600)];
            $chars+=iconv_strlen($scene['title'].$scene['text'],'UTF-8');
        }
        if ($chars>5000) throw new InvalidArgumentException('story_length');
        // Selected photos can be a subset, but their order must agree with the saved gallery.
        if ($seen!==array_values(array_filter($media,static fn($id)=>in_array($id,$seen,true)))) throw new InvalidArgumentException('story_order');
        return $out;
    }
}
