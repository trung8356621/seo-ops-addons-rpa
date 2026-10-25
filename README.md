# Social Automation Addon (RPA Control Plane)

Addon độc lập quản lý Control Plane, Package Registry, Script Catalog, Agent Registry, Session Registry, Task Orchestrator và RPA Integration cho hệ sinh thái Omnichannel / SEO-OPS.

- **Addon Slug**: `social-automation`
- **Namespace**: `Omnichannel\Addons\SocialAutomation\`
- **Database Connection**: `omi_social_automation`
- **Service Provider**: `Omnichannel\Addons\SocialAutomation\SocialAutomationServiceProvider`
- **Panel Provider**: `Omnichannel\Addons\SocialAutomation\Providers\SocialAutomationPanelProvider`

---

## 1. Cấu trúc thư mục

```text
seo-ops-addons-rpa/
├── addon.json
├── database/
│   └── migrations/
├── docs/
│   ├── FAILURE_RECOVERY.md
│   └── RPA_INTEGRATION_HANDOFF.md
├── resources/
│   ├── lang/
│   └── views/
├── routes/
│   ├── api-services.php
│   └── rpa-admin.php
├── src/
│   ├── Artifacts/
│   ├── Console/
│   ├── Contracts/
│   ├── Domain/
│   ├── Enums/
│   ├── Events/
│   ├── Filament/
│   ├── Http/
│   ├── Jobs/
│   ├── Listeners/
│   ├── Models/
│   ├── Providers/
│   ├── Services/
│   └── Support/
└── tests/
    ├── Feature/
    └── Unit/
```

---

## 2. Tích hợp Local với SEO-OPS qua Windows Directory Junction

Để SEO-OPS Client và SEO-OPS Addons nhận diện addon này ở môi trường phát triển local mà không cần nhân bản source:

1. Đặt repository này tại:
   `D:\work-app\new\seo-ops-addons-rpa`

2. Tạo **Windows Directory Junction** từ thư mục `social-automation` trong `omnichannel-addons` trỏ đến repository này:
   ```cmd
   mklink /J D:\work\omnichannel-addons\social-automation D:\work-app\new\seo-ops-addons-rpa
   ```

3. Kiểm tra liên kết:
   ```powershell
   Get-Item D:\work\omnichannel-addons\social-automation | Select-Object Name, LinkType, Target
   ```

*Lưu ý: Không dùng Windows Shortcut (`.lnk`) vì PHP và Composer không thể tự phân giải path qua file shortcut.*

---

## 3. Kiểm tra kiểm thử (Targeted Tests)

Chạy bộ test từ SEO-OPS Client:

```powershell
cd D:\work\omnichannel-client
.\vendor\bin\phpunit.bat -c phpunit.xml ..\omnichannel-addons\social-automation\tests
```

Hoặc kiểm tra route Social Automation / RPA:

```powershell
php artisan route:list --path=rpa
```

---

## 4. Ghi chú triển khai Production / VPS

Windows Junction là cơ chế cục bộ dành cho môi trường phát triển Windows local.
Khi triển khai lên máy chủ Linux / VPS:
- Sử dụng Git submodule, Composer package riêng hoặc symlink Linux (`ln -s`) tùy thuộc vào chiến lược triển khai CI/CD của hệ thống.
- Tuyệt đối không commit các file liên kết hệ thống vào kho Git chung.
