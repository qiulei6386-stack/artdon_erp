<?php
declare(strict_types=1);

namespace Artdon\Centers\Customer;

use Artdon\Centers\Base\Form;
use Artdon\Centers\Base\Layout;
use Artdon\Centers\Base\UserDirectory;

/** 客户表单（新建、编辑共用）与选择负责人的控件。 */
final class CustomerForm
{
    /**
     * 客户资料字段。$c 为当前值（新建时为空数组），$prefix 区分同页多个表单。
     */
    public static function fields(array $c, string $prefix): string
    {
        $o = CustomerService::options();
        $p = ['prefix' => $prefix];
        $country = (string) ($c['country'] ?? '');
        $countryOptions = $o['country'];
        if ($country !== '' && !isset($countryOptions[Country::normalize($country)])) {
            $countryOptions = [$country => $country . '（原值）'] + $countryOptions;
        }
        $level = (string) ($c['level'] ?? '');
        $levels = $o['level'];
        if ($level !== '' && !isset($levels[$level])) {
            $levels = [$level => $level . '（原值）'] + $levels;
        }
        $life = (string) ($c['lifecycle_key'] ?? '');
        $lifes = $o['lifecycle'];
        if ($life !== '' && !isset($lifes[$life])) {
            $lifes = [$life => $life . '（原值）'] + $lifes;
        }
        $h = '<p class="alert alert-error form-error span-2" data-form-error hidden></p>';
        $h .= '<div class="span-2 form-section">基本信息</div>';
        $h .= Form::input('customer_name', '客户名称', (string) ($c['customer_name'] ?? ''), [], $p + ['required' => true, 'maxlength' => 190]);
        $h .= Form::input('customer_name_en', '英文名', (string) ($c['customer_name_en'] ?? ''), [], $p + ['maxlength' => 190]);
        $h .= Form::select('country', '国家', $countryOptions, $countryOptions ? (isset($countryOptions[$country]) ? $country : Country::normalize($country)) : '', [], $p + ['required' => true, 'placeholder' => '请选择国家']);
        $h .= Form::input('city', '城市', (string) ($c['city'] ?? ''), [], $p + ['maxlength' => 120]);
        $h .= '<div class="span-2">' . Form::input('address', '地址', (string) ($c['address'] ?? ''), [], $p + ['maxlength' => 500]) . '</div>';
        $h .= Form::input('website', '网站', (string) ($c['website'] ?? ''), [], $p + ['maxlength' => 255, 'placeholder' => 'www.example.com']);
        $h .= Form::input('email', '公司邮箱', (string) ($c['email'] ?? ''), [], $p + ['type' => 'email', 'maxlength' => 190]);
        $h .= Form::input('phone', '电话', (string) ($c['phone'] ?? ''), [], $p + ['type' => 'tel', 'maxlength' => 80]);
        $h .= Form::input('whatsapp', 'WhatsApp', (string) ($c['whatsapp'] ?? ''), [], $p + ['maxlength' => 80]);
        $h .= Form::input('wechat', '微信', (string) ($c['wechat'] ?? ''), [], $p + ['maxlength' => 120]);
        $h .= Form::input('customer_code', '客户代码', (string) ($c['customer_code'] ?? ''), [], $p + ['maxlength' => 80, 'hint' => '可不填；填了不能和别的客户重复']);
        $h .= '<div class="span-2 form-section">分类</div>';
        $h .= Form::select('level', '等级', $levels, $level, [], $p + ($levels ? ['placeholder' => '默认'] : []));
        $h .= Form::select('lifecycle_key', '阶段', $lifes, $life, [], $p + ($lifes ? ['placeholder' => '默认'] : []));
        $h .= self::checks('source_tags', '来源', $o['source'], (array) ($c['source_tags'] ?? []), $prefix);
        $h .= self::checks('promotion_channels', '推广方式', $o['promotion_channel'], (array) ($c['promotion_channels'] ?? []), $prefix);
        $groups = GroupService::options();
        if ($groups) {
            $current = array_map('strval', array_map('intval', array_column((array) ($c['groups'] ?? []), 'id')));
            $h .= self::checks('group_ids', '分组', array_combine(array_map('strval', array_keys($groups)), array_values($groups)), $current, $prefix);
        }
        $h .= '<div class="span-2">' . Form::textarea('remark', '备注', (string) ($c['remark'] ?? ''), [], $p + ['maxlength' => 5000]) . '</div>';
        return $h;
    }

    /** 新建时的第一个联系人。 */
    public static function contactFields(string $prefix): string
    {
        $p = ['prefix' => $prefix];
        $h = '<div class="span-2 form-section">主联系人<span class="muted">（可不填，建好后在详情页添加）</span></div>';
        $h .= Form::input('contact_name', '姓名', '', [], $p + ['maxlength' => 120]);
        $h .= Form::input('contact_position', '职位', '', [], $p + ['maxlength' => 120]);
        $h .= Form::input('contact_email', '邮箱', '', [], $p + ['type' => 'email', 'maxlength' => 190]);
        $h .= Form::input('contact_phone', '电话', '', [], $p + ['type' => 'tel', 'maxlength' => 80]);
        $h .= Form::input('contact_whatsapp', 'WhatsApp', '', [], $p + ['maxlength' => 80]);
        return $h;
    }

    /** 第一负责人（下拉，按部门分组）+ 协助负责人（多选）。 */
    public static function ownerFields(string $prefix, int $selected, bool $required = true): string
    {
        $id = $prefix . '-owner_user_id';
        $h = '<div class="field"><label class="field-label" for="' . Layout::e($id) . '">第一负责人' . ($required ? '<span class="field-req" aria-hidden="true">*</span>' : '') . '</label>';
        $h .= '<select class="input select" id="' . Layout::e($id) . '" name="owner_user_id"' . ($required ? ' required' : '') . '><option value="">请选择</option>' . self::userOptions([$selected]) . '</select></div>';
        $aid = $prefix . '-assistant_ids';
        $h .= '<div class="field"><label class="field-label" for="' . Layout::e($aid) . '">协助负责人</label>';
        $h .= '<select class="input select select-multi" id="' . Layout::e($aid) . '" name="assistant_ids[]" multiple size="4" data-array="assistant_ids">' . self::userOptions([]) . '</select>';
        $h .= '<p class="field-hint">可不选；按住 Ctrl（Mac 为 ⌘）可多选</p></div>';
        return $h;
    }

    /** @param list<int> $selected */
    public static function userOptions(array $selected): string
    {
        $h = '';
        foreach (UserDirectory::grouped() as $dept => $users) {
            $h .= '<optgroup label="' . Layout::e($dept) . '">';
            foreach ($users as $uid => $name) {
                $h .= '<option value="' . (int) $uid . '"' . (in_array((int) $uid, $selected, true) ? ' selected' : '') . '>' . Layout::e($name) . '</option>';
            }
            $h .= '</optgroup>';
        }
        return $h;
    }

    /** @param array<string, string> $options @param list<string> $checked */
    private static function checks(string $name, string $label, array $options, array $checked, string $prefix): string
    {
        foreach ($checked as $k) {
            if (!isset($options[$k])) {
                $options[$k] = $k;
            }
        }
        $h = '<fieldset class="field span-2 check-group" data-array="' . Layout::e($name) . '"><legend class="field-label">' . Layout::e($label) . '</legend><div class="check-list">';
        if (!$options) {
            $h .= '<span class="muted">字典里还没有选项</span>';
        }
        foreach ($options as $key => $text) {
            $id = $prefix . '-' . $name . '-' . preg_replace('/[^a-z0-9_-]/i', '_', (string) $key);
            $h .= '<label class="check" for="' . Layout::e($id) . '"><input type="checkbox" id="' . Layout::e($id) . '" name="' . Layout::e($name) . '[]" value="' . Layout::e((string) $key) . '"'
                . (in_array((string) $key, $checked, true) ? ' checked' : '') . '><span>' . Layout::e($text) . '</span></label>';
        }
        return $h . '</div></fieldset>';
    }
}
