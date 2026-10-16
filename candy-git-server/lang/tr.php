<?php

/**
 * Turkish translations for candy-git-server.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'user.invalid_ssh_key'     => 'Geçersiz SSH açık anahtar biçimi',
    'repo.create_dir_failed'   => 'Depo dizini oluşturulamadı: {path}',
    'repo.git_init_failed'     => 'git init başarısız: {output}',
    'config.not_found'         => 'Yapılandırma dosyası bulunamadı: {path}',
    'config.read_failed'       => 'Yapılandırma okunamadı: {path}',
    'ssh.user_cannot_create'   => '{viewer} kullanıcısı depo oluşturamaz',
    'cli.banner'               => 'CandyGitServer v{version}',
    'cli.config_summary'       => "Data path:  {data_path}\nSSH:        {ssh_addr}\nHTTP:       {http_addr}\nGit daemon: {git_addr}",
    'cli.starting_servers'     => 'Sunucular başlatılıyor...',
    'cli.note_ssh2_required'   => '(Tam daemon modu ssh2 eklentisi ve çalışan SSH daemonu gerektirir.)',
    'cli.note_run_init'        => "('candy-git-server init' çalıştırarak veri dizinini başlatın.)",
    'cli.repos_header'         => 'Depolar:',
    'cli.repo_listing_entry'   => '  - {name}',
    'cli.repo_listing_plain'   => '  {name}',
    'cli.note_not_a_daemon'    => 'Henüz daemon olarak çalışmıyor (daemon modu bir süreç yöneticisi gerektirir.)',
    'cli.note_http_help'       => 'Git\'i HTTP üzerinden sunmak için web sunucusunu bu betiğe yönlendirin.',
    'cli.note_ssh_help'        => 'SSH erişimi için ters tünel kullanın veya sshd\'yi ForceCommand ile yapılandırın.',
    'cli.already_initialized'  => 'Zaten başlatılmış: {path}',
    'cli.initializing'         => 'CandyGitServer veri dizini başlatılıyor: {path}',
    'cli.done'                 => 'Tamamlandı.',
    'cli.next_steps'           => 'Sonraki adımlar:',
    'cli.next_step_1'          => '  1. {path}/config.yaml dosyasını düzenleyin',
    'cli.next_step_2'          => '  2. SSH ana bilgisayar anahtarı oluşturun: ssh-keygen -t ed25519 -f {path}/ssh/soft_serve_host',
    'cli.next_step_3'          => '  3. CANDY_GIT_SERVER_INITIAL_ADMIN_KEYS=ssh-açık-anahtarı ayarlayın',
    'cli.next_step_4'          => '  4. Çalıştırın: candy-git-server serve --config {path}/config.yaml',
    'cli.usage_user_root'      => 'Kullanım: candy-git-server user add|key|list',
    'cli.usage_user_add'       => 'Kullanım: candy-git-server user add <username>',
    'cli.usage_user_key'       => 'Kullanım: candy-git-server user key <username> [key-file]',
    'cli.user_created'         => "'{username}' kullanıcısı oluşturuldu (admin: true)",
    'cli.user_key_hint'        => "SSH açık anahtarı eklemek için 'candy-git-server user key {username} < key.pub' kullanın.",
    'cli.user_key_read_failed' => 'Anahtar okunamadı: {file}',
    'cli.user_key_added'       => "'{username}' kullanıcısına anahtar eklendi",
    'cli.user_keys_header'     => 'Yetkili anahtarlar:',
    'cli.user_key_entry'       => '  {prefix}...',
    'cli.user_list_empty'      => "Kullanıcılar:\n  (Kayıtlı kullanıcı yok. 'candy-git-server user add <username>' kullanın)",
    'cli.usage_repo_root'      => 'Kullanım: candy-git-server repo list|create|info',
    'cli.usage_repo_create'    => 'Kullanım: candy-git-server repo create <name>',
    'cli.usage_repo_info'      => 'Kullanım: candy-git-server repo info <name>',
    'cli.no_repos'             => 'Henüz depo yok.',
    'cli.repo_listing_none'    => '  (henüz depo yok)',
    'cli.repo_invalid_name'    => 'Geçersiz depo adı: {name} (yalnızca alfanümerik, nokta, alt çizgi, kısa çizgi kullanın)',
    'cli.repo_created'         => "'{name}' deposu oluşturuldu ({path})",
    'cli.repo_clone_url'       => 'Klon URL\'si: ssh://localhost:23231/{name}',
    'cli.repo_not_found'       => 'Depo bulunamadı: {name}',
    'cli.repo_info'            => "İsim:         {name}\nAçıklama:  {description}\nHerkese açık:       {is_public}\nÖzel:      {is_private}\nPush izin:   {allow_push}\nYol:         {path}\nDallar:     {branches}\nEtiketler:         {tags}",
    'cli.bool_yes'             => 'evet',
    'cli.bool_no'              => 'hayır',
    'cli.none_value'           => '(yok)',
    'cli.unknown_command'      => "Bilinmeyen komut: {cmd}\n  Yardım için 'candy-git-server help' çalıştırın.",
    'cli.help'                 => "CandyGitServer — Kendi Barındırdığınız Git Sunucusu\n\nKullanım:\n  candy-git-server <komut> [seçenekler]\n\nKomutlar:\n  serve [--config path]    Git sunucusunu başlat\n  init [data-path]         Yeni bir veri dizini başlat\n  user add <username>      Kullanıcı oluştur\n  user key <username> <file>  Kullanıcı için SSH açık anahtarı ekle (stdin için - kullanın)\n  user list                Kullanıcıları listele\n  repo list [data-path]    Depoları listele\n  repo create <name> [data-path]  Depo oluştur\n  repo info <name> [data-path]    Depo bilgilerini göster\n  help, --help, -h         Bu yardımı göster\n  version, --version, -v   Sürümü göster\n\nOrtam Değişkenleri:\n  CANDY_GIT_SERVER_DATA_PATH    Veri dizini (varsayılan: /tmp/candy-git-server)\n\nÖrnekler:\n  candy-git-server init ~/candy-git-server-data\n  candy-git-server serve --config ~/candy-git-server-data/config.yaml\n  candy-git-server repo create proje-adi\n  candy-git-server user key alice ~/.ssh/id_ed25519.pub",
];
