# 聚合图床 Pro 🚀
**WordPress图片托管与替换解决方案**  
![GitHub stars](https://img.shields.io/github/stars/yourname/juheimg-pro) 
![WordPress Version](https://img.shields.io/wordpress/plugin/v/juheimg-pro)

## 核心功能 🔥
1. **智能检测替换**  
   正则表达式驱动的内容扫描引擎，支持域名白名单配置如（`www.baidu.com`）
   
2. **多平台对接**  
   深度集成聚合图床API，支持：
   - 图片水印
   - 相册分类
   - 图片压缩
   - ​WebP转换​
   - 超时重试机制（默认10秒）

3. **高性能处理**  
   ```php
   define('XIR_PER_PAGE', 20); // 批量处理20篇文章/批次
   define('XIR_REGEX_CACHE_KEY', 'xianzhidaquan'); // 正则表达式缓存优化
   ```

4. **可视化进度监控**  
   包含成功率统计、剩余任务量预测等关键指标

---

## 快速开始 🚀
### 安装步骤
1. 克隆仓库到WordPress插件目录
2. 在WordPress后台激活插件

### 配置指南
通过 **设置 → 图片替换设置** 配置：
- 🔑 API Token：从[聚合图床](https://www.superbed.cn/signup?link=0UJWK)聚合图床获取的访问凭证
- 🌐 目标域名：需要替换的图片源域名（多域名用逗号分隔）
- 🖼️ 水印设置：启用/禁用自动水印功能
- 📁 相册分类：指定图片存储目录

---

## 技术架构 ⚙️
### 核心模块
| 模块 | 技术实现 | 性能指标 |
|------|---------|---------|
| 正则引擎 | PCRE预编译模式 | 缓存时间：7天 |
| 图片处理 | cURL多线程传输 | 超时：10秒 |
| 任务调度 | WP Cron + AJAX | 吞吐量：20篇/批次 |

### 扩展能力
```mermaid
graph TD
    A[WordPress文章] --> B(正则匹配)
    B --> C{图片检测}
    C -->|匹配成功| D[图床API上传]
    C -->|未匹配| E[标记完成]
    D --> F[内容替换]
```

---

## 贡献指南 👥
欢迎通过以下方式参与项目：
1. 提交PR改进正则表达式匹配逻辑
2. 补充多语言文档（当前支持中文）
3. 扩展支持更多图床平台


---

## 许可证 📜
[GPL-3.0](https://github.com/52nfw/image-replacer-pro?tab=GPL-3.0-1-ov-file#readme)© 2025 小小随风
