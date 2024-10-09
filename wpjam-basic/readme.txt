=== WPJAM Basic ===
Contributors: denishua
Donate link: https://wpjam.com/
Tags: WPJAM, Memcached, 性能优化
Requires at least: 6.4
Requires PHP: 7.4
Tested up to: 6.6
Stable tag: trunk
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

WPJAM Basic 是我爱水煮鱼博客多年来使用 WordPress 来整理的优化插件，WPJAM Basic 除了能够优化你的 WordPress，也是 WordPress 果酱团队进行 WordPress 二次开发的基础。

== Description ==

**WPJAM Basic 可能和一些插件可能存在冲突，使用如有问题，请提供 log，才方便帮忙排查，获取冲突的 log 的方式：<a href="https://blog.wpjam.com/m/how-to-debug-wordpress/">https://blog.wpjam.com/m/how-to-debug-wordpress/</a>。**

WPJAM Basic 是<a href="http://blog.wpjam.com/">我爱水煮鱼博客</a>多年来使用 WordPress 来整理的优化插件，WPJAM Basic 除了能够优化你的 WordPress ，也是 WordPress 果酱团队进行 WordPress 二次开发的基础。

WPJAM Basic 主要功能，就是去掉 WordPress 当中一些不常用的功能，比如文章修订等，还有就是提供一些经常使用的函数，比如获取文章中第一张图，获取文章摘要等。

如果你的主机安装了 Memcacached 等这类内存缓存组件和对应的 WordPress 插件，这个插件也针对提供一些针对一些常用的插件和函数提供了对象缓存的优化版本。

详细介绍和安装说明： <a href="http://blog.wpjam.com/project/wpjam-basic/">http://blog.wpjam.com/project/wpjam-basic/</a>。

除此之外，WPJAM Basic 还支持多达十七个扩展，你可以根据自己的需求选择开启：

| 扩展 | 简介 | 
| ------ | ------ |
| 文章数量 | 设置不同页面不同的文章列表数量，不同的分类不同文章列表数量。 |
| 文章目录 | 自动根据文章内容里的子标题提取出文章目录，并显示在内容前。 |
| 相关文章 | 根据文章的标签和分类，自动生成相关文章，并在文章末尾显示。 |
| 用户角色 | 用户角色管理，以及用户额外权限设置。 |
| 统计代码 | 自动添加百度统计和 Google 分析代码。 |
| 百度站长 | 支持主动，被动，自动以及批量方式提交链接到百度站长。 |
| Bing 站长工具 | 实现提交链接到 Microsoft Bing，让博客的文章能够更快被 Bing 收录。 |
| 移动主题 | 给移动设备设置单独的主题，以及在PC环境下进行移动主题的配置。 |
| 301 跳转 | 支持网站上的 404 页面跳转到正确页面。 |
| 简单 SEO | 设置简单快捷，功能强大的 WordPress SEO 功能。 |
| SMTP 发信 | 简单配置就能让 WordPress 使用 SMTP 发送邮件。 |
| 常用短代码 | 添加 list table 等常用短代码，并在后台罗列所有系统所有短代码。|
| 文章浏览统计 | 统计文章阅读数，激活该扩展，请不要再激活 WP-Postviews 插件。|
| 文章快速复制 | 在后台文章列表，添加一个快速复制按钮，点击可快复制一篇草稿用于新建。 |
| 摘要快速编辑 | 在后台文章列表，点击快速编辑之后也支持编辑文章摘要。 |
| Rewrite 优化 | 清理无用的 Rewrite 代码，和添加自定义 rewrite 代码。 |
| 文章类型转换器 | 文章类型转换器，可以将文章在多种文章类型中进行转换。 |
| 自定义文章代码 | 在文章编辑页面可以单独设置每篇文章 Head / Footer 代码。 |

== Installation ==

1. 上传 `wpjam-basic`目录 到 `/wp-content/plugins/` 目录
2. 激活插件，开始设置使用。

== Changelog ==

= 6.6.2 = 
* 新增 wpjam_exists 函数
* 新增 wpjam_slice 函数

= 6.6 =
* 新增 wpjam_include 函数用于加载文件
* 新增 wpjam_style 和 wpjam_script 函数
* 新增 get_user_field 函数
* 新增 wpjam_get_static_cdn 函数
* 新增 wpjam_throw 函数
* 新增 wpjam_get_instance 函数
* 新增 wpjam_lock 函数
* 新增 wpjam_sort 函数
* 新增 wpjam_add_pattern 函数
* 新增 wpjam_import 函数
* 新增 wpjam_export 函数
* 新增 wpjam_at 函数
* 新增 wpjam_add_at 函数
* 优化 show_if 功能
* 其他优化和bug修复

= 6.5 =
* 使用注解的方式实现注册类支持能力
* List Table 操作 views 自动更新 
* 复选框字段支持开关模式
* 301跳转升级为链接跳转，并支持正则匹配。
* 使用 PHP 7.4 箭头函数优化代码
* 兼容 PHP 8 及以上版，优化使用 is_callable 和不接受 null 参数的函数
* 兼容 PHP 8 废弃在可选参数后声明强制参数
* 新增 wpjam_db_transaction 函数用于数据库事务
* 新增 wpjam_call_for_blog 函数用于多站点调用
* 新增 wpjam_load_pending 函数
* 新增 wpjam_diff 函数
* WPJAM_Register 的 get 方法新增第二个参数 $by
* list_table 增加 sticky_columns 功能
* WPJAM_Field 新增 render 回调函数
* 优化 wpjam_lazyload 函数
* 优化 wpjam_generate_random_string 函数
* 优化 wpjam_get_posts 和 wpjam_get_terms 函数
* 优化 WPJAM_DB 的缓存处理，支持 lazyload_key 和 pending_queue
* 后台弹窗自动自适应大小
* 后台设置选项页面支持重置操作

= 6.4 =
* 基于 PHP 7.4 重构
* 调整文件目录，将后台相关函数放到一个文件中。
* 后台插件页面可以在初始化之前设置页面的 data_type。
* 通过 current_theme_supports 来控制样式和脚本是否主题已经集成。
* WPJAM_Register 新增 re_register / register_sub 方法 / 优化 match 方法
* 将 WPJAM_Register 的 data_type 配置移到 WPJAM_List_Table
* 将 WPJAM_Register 的 registered 配置改成 registered 方法
* 将 WPJAM_Register 的 custom_fields 和 custom_args 的配置合并到 defaults 配置
* 后台 List Table 新增导出操作支持，列表 AJAX 返回更加细化
* 优化自定义文章类型和分类模式获取名称的方式
* 新增 WPJAM_Platforms Class，用于多平台处理
* 新增函数 wpjam_url，根据目录获取 url
* 新增 wpjam_has_bit / wpjam_add_bit / wpjam_remove_bit 函数用于位运算
* 新增函数：wpjam_move / wpjam_get_all_terms / wpjam_html_tag_processor / wpjam_dashboard_widget

= 6.3 =
* 「文章浏览」扩展支持设置文章初始浏览数
* 新增函数 wpjam_add_admin_load，实现后台功能按需加载
* 新增函数 wpjam_value_callback，支持实例化的 value_callback 函数
* 新增函数 base64_urldecode / base64_urlencode，实现 URL 安全的 Base64 编码和解码
* 新增函数 wpjam_generate_jwt / wpjam_verify_jwt， 实现 JWT 生成和验证
* WPJAM_Field 增加 before / after 属性，统一使用 button_field 作为各种按钮的自定义文本
* WPJAM_Register 增加 admin_load config
* WPJAM_Model 支持 meta_input 方法
* WPJAM_DB 新增 group_cache_key 属性，新增 query 方法
* 优化相关文章功能，支持最新 WP_Query 缓存机制

= 6.2 =
* 新增函数 wpjam_match 用于各种数据匹配
* 新增函数 wpjam_try / wpjam_call 处理异常
* 新增函数 wpjam_register_config / wpjam_get_config 用于生成和获取全局配置接口
* 新增函数 wpjam_register_meta_option / wpjam_get_meta_options，用于注册和获取 meta option。
* 新增函数 get_screen_option 用于获取界面选项
* 新增函数 wpjam_add_option_section 向已有的设置页面添加标签页
* 新增函数 wpjam_tag，支持将文本加上某个标签和属性
* 新增函数 wpjam_date / wpjam_strtotime 用于日期处理。s
* 新增函数 wpjam_upload，用于统一处理文件上传
* 新增函数 wpjam_scandir 支持通过 callback 处理扫描的文件夹
* 新增函数 wpjam_register_error_setting 用于统一注册错误信息
* 新增 Class WPJAM_List_Table_View，用于定义后台 List Table 的快速筛选链接
* 新增 class WPJAM_File，以及对应的函数，用于文件，链接以及路径之间的转换
* 新增 class WPJAM_Image，以及对应的函数用于图片各种处理
* 新增 class WPJAM_Array，以及对应的函数，用于数组各种操作，支持链式调用
* 新增 class WPJAM_Attr / WPJAM_Tag，以及对应的函数，用于标签和属性处理
* 新增 Class WPJAM_Exception 支持将 wp_error 将异常抛出
* 新增 Class WPJAM_Args，作为所有有 args 参数类的基类，并实现 ArrayAccess, IteratorAggregate, JsonSerializable 等接口
* 新增 Trait WPJAM_Call_Trait，WPJAM_Items_Trat / WPJAM_Instance
* 新增 WPJAM_Post / WPJAM_Term 的 update_callback 方法，支持同时更新文章和分类的本身字段和自定义字段
* WPJAM_Register 继承 WPJAM_Args 来丰富功能
* WPJAM_Register 增加 add_hooks 支持，支持 group config。
* WPJAM_JSON 支持 data_type 接口，用于前端自动完成
* WPJAM_List_Table_Column 支持 column_style 参数
* wpjam_http_request 支持 headers 参数
* wpjam_arg 属性新增 newline 参数
* WPJAM_Field 支持 data_type 的数据源联动处理，下拉菜单，单选和复选框都支持其他选项
* WPJAM_Field 新增 timestamp 组件，显示为时间，存储为时间戳
* WPJAM_Field 新增文件上传组件，实现无需经过媒体库直接上传
* 增强后台分类和标签设置，可以不进入分类和标签的详情编辑页，就可以更新所有信息
* CDN 支持其他扩展在后台也使用 CDN 链接，扩展支持设置支持所有图片扩展
* 如果文章内容是序列化的，去掉 wp_filter_post_kses 处理
* 多层分类支持多级联动显示字段

= 6.1 =
* 接口请求的时候不显示 PHP 警告信息
* 菜单 summary 参数支持传递文件路径，程序会自动根据文件头信息
* WPJAM_Register 增加开关属性处理，支持注册时候自动开启和生成后台配置字段等能力
* 新增函数 wpjam_load_extends
* 新增函数 wpjam_register_handler / wpjam_get_handler
* 新增函数 wpjam_load，用于处理基于 action 判断加载
* 新增函数 wpjam_get_current_var / wpjam_set_current_var
* 新增函数 wpjam_get_platform_options 用于获取平台信息
* 新增函数 wpjam_register_data_type / wpjam_get_data_type_object
* 新增函数 wpjam_get_post_type_setting / wpjam_update_post_type_setting
* 新增函数 wpjam_get_taxonomy_setting / wpjam_update_taxonomy_setting
* 新增函数 wpjam_add_post_type_field / wpjam_add_taxonomy_field 
* 增强函数 wpjam_if / wpjam_compare
* 新增 Class WPJAM_Error 用于自定义错误信息显示
* 新增 Class WPJAM_Option_Model 用于所有设置页面 Class 的基类
* 新增 Class WPJAM_Screen_Option 用于后台页面参数和选项处理
* 新增 Class WPJAM_Register，支持 group 和独立子类两种方式注册
* 新增 Class WPJAM_Meta_Option，用于支撑所有 Meta 选项注册
* 新增 Class WPJAM_Extend_Type，用于所有插件的扩展管理
* 函数 wpjam_register 新增 priority 参数
* 函数 wpjam_register_option 新增 field_default / menu_page 参数
* Class WPJAM_Register 新增 init 参数，支持在 WordPress init 时回调
* 增强 Class WPJAM_Option_Setting
* 增强「文章浏览」扩展，支持批量增加浏览数
* 增强「相关文章」扩展，新增日期限制
* 完全重构 WPJAM_Field，通过子类把功能分拆，并支持 JSON Schema validate
* 使用 wp_content_img_tag filter 改进 CDN 中图片处理。

= 6.0 =
* object-cache.php 支持 6.0 的批量操作
* 优化「文章目录」扩展，使用ID进行锚点定位
* 「简单 SEO」 扩展支持设置唯一的 TDK
* 新增登录界面去掉语言切换器功能
* 缩略图设置支持设置多张默认缩略图
* 增强 wpjam_send_json 数据处理能力
* 基于 WP 5.9 优化 lazy loading 处理
* 新增函数 wpjam_generate_verification_code
* 新增函数 wpjam_verify_code
* 新增 Class WPJAM_Fields，用于表单渲染
* 增强 Class WPJAM_JSON，整合接口验证
* wpjam_fields 函数支持 wrap_tag 参数
* WPJAM_Field 新增 json schema 解析和验证功能
* WPJAM_Fields 新增 get_defaults 方法
* WPJAM_Page_Action 支持多个提交按钮
* WPJAM_List_Table 支持多个提交按钮
* 火山引擎 veImageX 也支持自动 WebP 转换

= 5.9 =
* CDN 加速水印设置增加最小图片设置
* CDN 加速新增支持火山引擎的 veImageX
* 解决部分博客插件冲突造成文章列表页空白的问题
* 解决 show_if 和默认 disabled 字段兼容问题
* 在文章列表页新增「上传外部图片」操作
* 全面实现后台文章和分类列表页 AJAX 操作
* 全面优化 CDN 加速功能，提供更多选项设置
* 新增函数 wpjam_lazyload，用于后端懒加载
* 新增函数 wpjam_get_by_meta 直接在 meta 表中查询数据
* 新增函数 wpjam_compare，用于两个数据比较
* 新增函数 wpjam_unserialize，用于反序列化失败之后修复数据，再次反序列化
* 新增函数 wpjam_is_external_url，用于判断外部链接和图片
* 新增函数 wpjam_map_meta_cap，用于将新增的权限映射到元权限
* 新增函数 wpjam_get_ajax_data_attr
* 新增和优化 Gravatar 加速和 Google 字体加速服务
* 新增 field 支持 minlength / maxlength 服务端验证
* WPJAM_Field 支持 is_boolean_attribute 的判断
* WPJAM_Page_Action 新增 validate 参数使支持字段验证
* 文章类型转换支持在文章列表页进行转换操作
* mu-img 图片点击支持放大显示
* 取消「前台不加载语言包」功能

= 5.8 =
* 实现后台的文章列表和分类列表页 AJAX 操作
* 取消「屏蔽 REST API」功能
* 取消「禁止admin用户名」功能
* 修正 WPJAM_Model 同时存在 __call / __callStatic 上下文问题
* 通过 query_data 实现带参数的 list_table 菜单显示自动处理
* 新增自定义表 meta 查询 
* 新增 WPJAM_Field 分组打横显示功能
* 新增 wpjam_iframe JS 方法，默认在后台右下角显示
* 新增 class WPJAM_Bind 用于用户相关业务连接
* 新增 class WPJAM_Phone_Bind 用于手机号码相关业务连接
* 新增 class WPJAM_CDN_Type，优化 CDN 处理
* 新增 class WPJAM_AJAX 用于前台统一 AJAX 处理
* 新增 class WPJAM_Calendar_List_Table 用于日历后台
* 新增函数 wpjam_render_list_table_column_items
* 新增函数 wpjam_register_meta_type
* 新增函数 wpjam_register_bind 
* 新增函数 wpjam_get_bind_object
* 新增函数 wpjam_zh_urlencode

= 5.7 =
* WPJAM_Field 支持 required 后端判断
* 解决文章时间戳相同引起的排序问题
* 新增函数 wpjam_validate_field_value
* 新增函数 wpjam_except
* 新增函数 wpjam_get_taxonomy_query_key 
* 新增函数 wpjam_get_post_id_field 
* 新增函数 wpjam_get_term_id_field

= 5.6 = 
* 跳过其他版本直接升级到 5.6 / WordPress 保持一致
* CDN 文件扩展设置和媒体库对应
* 调用 save_post 改成调用 wp_after_insert_post

= 5.2 =
* 新增函数 wpjam_register_route 自定义路由
* 新增函数 wpjam_register_json 自定义 API 接口
* wpjam_add_menu_page 支持 load_callback 参数
* form 页面支持 summary
* wpjam_register_option 支持自定义 update_callback
* wpjam_register_option 支持 reset 选项

= 5.1 =
* 支持腾讯云 COS 的 WebP 转换，节省流量
* 类型转换函数全部切换成强制类型转换
* 新增 Class WPJAM_Lazyloader
* CDN 后台媒体库只镜像图片
* CDN 远程图片功能上传到媒体库
* 支持停用 CDN，切换回使用本站图片

= 5.0 =
* 缩略图设置支持应用到原生的缩略图中
* 新增图片的 $max_width 处理
* 新增函数 wpjam_parse_query
* 新增函数 wpjam_render_query
* 支持评论者头像存到 commentmeta 中

= 4.6 =
* 优化反斜杠转义的处理
* 增强 class WPJAM_Cron

= 4.5 =
* 新增函数 wpjam_download_url，用于下载远程图片
* 新增函数 wpjam_is_image，用于判断当前链接是否为图片
* 新增函数 wpjam_get_plugin_page_setting
* 新增函数 wpjam_register_list_table_action
* 新增函数 wpjam_register_list_table_column
* 新增函数 wpjam_register_page_action
* 新增函数 wpjam_is_webp_supported，用于判断是否支持 webp
* 新增用户处理的 class WPJAM_User

= 4.4 =
* 阿里云 OSS 支持水印和 WebP 图片格式转换
* WPJAM_Field 增加 show_if 
* 兼容 PHP 7.4
* Google 字体加速服务 和 Gravatar 加速服务支持自定义

= 4.3 =
* 新增 wpjam_unicode_decode
* 新增函数 wpjam_admin_tooltip，用于后台提示
* 百度站长扩展支持快速收录

= 4.2 =
* 新增函数 wpjam_get_current_platform()，用于获取当前平台
* 新增验证文本文件管理 class WPJAM_Verify_TXT
* 全面提升插件的安全性

= 4.1 =
* 新增禁止古腾堡编辑器加载 Google 字体
* 常用短代码新增B站视频支持 [bilibili]
* 经典编辑器标签切换优化

= 4.0 =
* 新增 wpjam_is_json_request 函数
* 新增路径管理 Class WPJAM_Path 和对应函数

= 3.7 =
* 改进 object-cache.php，建议重新覆盖
* 远程图片支持复制到本地再镜像到云存储选项
* 新增「文章数量」扩展
* 新增「摘要快速编辑」扩展
* 新增「文章快速复制」扩展
* 新增后台文章列表页搜索支持ID功能
* 新增后台文章列表页作者筛选功能
* 新增后台文章列表页排序选型功能
* 新增后台文章列表页修改特色图片功能
* 新增后台文章列表页修改浏览数功能
* 「简单SEO」扩展支持列表页快速操作
* 「百度站长」扩展支持列表页批量提交
* 字段新增支持 name[subname] 方式的字段
* Class WPJAM_List_Table 新增拖动排序

= 3.6 =
* 「去掉URL中category」支持多级分类
* 新增屏蔽字符转码功能
* 新增屏蔽Feed功能
* 新增Google字体加速服务
* 新增Gravatar加速服务
* 新增移除后台界面右上角的选项
* 新增移除后台界面右上角的帮助
* 增强附件名新增时间戳功能
* 将文章页代码独立成独立扩展
* 「百度站长」扩展支持不加载推送 JS
* 「Rewrite」扩展支持查看所有规则

= 3.5 =
* 插件 readme 增加 PHP 7.2 最低要求
* Class WPJAM_List_Table 增强 overall 操作
* 「用户角色」扩展添加重置功能
* 修正自定义文章类型和自定义分类模式更新提示
* 加强「屏蔽Trackbacks」功能
* 去掉「屏蔽主题Widget」功能
* 增强 wpjam_send_json

= 3.4 =
* 新增「301跳转」扩展
* 新增「移动主题」扩展
* 新增「百度站长」扩展，修正预览提交
* 修正「简单SEO」的标题功能
* CDN 功能更好支持缩图和 HTTPS
* 「移动主题」扩展支持在后台启用移动主题

= 3.3 =
* 支持 UCLOUD 对象存储
* 支持屏蔽 Gutenberg
* 新增「相关文章」扩展

= 3.2 =
* 提供选项让用户去掉 URL 中category
* 提供选项让用户上传图片加上时间戳
* 增强 WPJAM SEO 扩展，支持 sitemap 拆分
* 更新 WPJAM 后台 Javascript 库

= 3.1 =
* 修正 WPJAM Basic 3.0 以后大部分 bug
* 新增 object-cache.php 到 template 目录

= 3.0 =
* 基于 PHP 7.2 进行代码重构，效率更高，更加快速
* 全AJAX操作后台

= 2.2 =
* 上架 WordPress 官方插件站
* 分拆功能组件
* WPJAM Basic 作为基础库使用

= 2.1 = 
* 新增「简单SEO」扩展
* 新增「短代码」扩展
* 新增 SMTP 扩展
* 新增「统计代码」扩展

= 2.0 =
* 初始版本直接来个 2.0 显得牛逼点