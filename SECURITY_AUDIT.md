# Relatório de Auditoria de Segurança — sysPass

**Data:** 2026-03-31
**Escopo:** Análise estática do código-fonte, dependências e configuração
**Status:** P0-1 resolvido (migração PHP 8.5). P0-2, P0-3 e demais pendentes.

---

## SEVERIDADE CRÍTICA

### 1. ~~PHP 7.4 End-of-Life~~ ✅ RESOLVIDO

~~O projeto requer `php ~7.4`, que está **sem suporte desde novembro de 2022**.~~

**Resolvido em 2026-03-31:** Migrado para PHP 8.5 (composer.json `>=8.1`, platform `8.5`). Todas as dependências atualizadas. Branch `migration/php85`. Aplicação testada e funcional.

### 2. ~~Desserialização Insegura (RCE Potencial)~~ ✅ RESOLVIDO

~~Múltiplos locais usam `unserialize()` **sem restrição de classes**.~~

**Resolvido em 2026-03-31:** `allowed_classes` adicionado em todas as chamadas `unserialize()`:
- Dados do banco: restrito à classe esperada (`Vault::class`, `Task::class`, etc.)
- Arrays puros (useInfo): `allowed_classes => false`
- Cache local (FileCache): `allowed_classes => true` (arquivos controlados pelo servidor)
- `Util::unserialize()`: restrito a `$dstClass`/`$srcClass`

### 3. ~~XXE — XML External Entity Injection~~ ✅ RESOLVIDO

~~Nenhuma chamada a `libxml_disable_entity_loader(true)` existe no projeto. Todos os `loadXML()` são vulneráveis.~~

**Resolvido em 2026-04-01:** Flag `LIBXML_NONET` adicionada em todas as chamadas `loadXML()` e `load()`:
- `lib/SP/Services/Import/XmlFileImport.php` — `loadXML(..., LIBXML_NONET)`
- `lib/SP/Services/Import/SyspassImport.php` — `loadXML(..., LIBXML_NONET)`
- `lib/SP/Http/XMLRPCResponseParse.php` — `loadXML(..., LIBXML_NONET)`
- `lib/SP/Storage/File/XmlHandler.php` — `load(..., LIBXML_NONET)` (2 locais)
- `lib/SP/Services/Export/XmlVerifyService.php` — `loadXML(..., LIBXML_NONET)` (2 locais)

**Nota:** No PHP 8.5, `libxml_disable_entity_loader()` foi removida e entity loading externo é desabilitado por padrão. `LIBXML_NONET` adiciona proteção contra SSRF (bloqueia acesso à rede durante parsing).

### 4. ~~XSS — Cross-Site Scripting (Múltiplos Vetores)~~ ✅ RESOLVIDO

~~Saída de variáveis sem `htmlspecialchars()` em templates.~~

**Resolvido em 2026-04-01:** `htmlspecialchars($value, ENT_QUOTES, 'UTF-8')` adicionado em todos os vetores identificados:
- `views/login/index.inc` — `from`, `from_hash` e `$sk` escapados em atributos `value=""`
- `views/account/viewpass.inc` — header, login e password escapados (texto e base64)
- `views/account/actions.inc` — data attributes e attributes loops escapados (`$key`/`$value`)
- `views/grid/datagrid-rows.inc` — `$value` em células e data attributes escapados
- `views/grid/datatabs-grid.inc` — `tabsRoute` escapado, `$data->getTitle()` protegido com `json_encode()` em contexto JS
- `views/account/files.inc` — todos os `$_getvar()` em data attributes escapados, IDs numéricos com cast `(int)`

### 5. ~~SQL Injection — Interpolação de String em Query~~ ✅ RESOLVIDO

~~Variáveis interpoladas diretamente em queries SQL.~~

**Resolvido em 2026-04-02:**
- `Database.php`: Adicionada validação regex de nome de tabela + backtick quoting
- `FileBackupService.php`: Whitelist de tabelas (`DatabaseUtil::$tables`) + backtick quoting no `SHOW CREATE TABLE`
- `DatabaseUtil.php`: `checkDatabaseTables()` reescrito com prepared statements (placeholders `?`)
- `Installer.php`: Validação de `dbName` com regex `^[a-zA-Z0-9_]+$` (substitui checagem apenas de `.`)
- `MySQL.php`: Nomes de banco/tabela vêm de input validado pelo Installer ou da whitelist `DatabaseUtil::$tables`; identificadores protegidos com backticks

---

## SEVERIDADE ALTA

### 6. Autenticação com Fallback MD5/SHA1

**Arquivo:** `lib/SP/Providers/Auth/Database/Database.php:132-134`

```php
return ($userLoginResponse->getPass() === sha1($salt . $pass)
    || $userLoginResponse->getPass() === md5($pass)
    || hash_equals(..., crypt($salt, $salt))
    || Hash::checkHashKey($pass, $hash));
```

Usuários com senhas legadas ainda autenticam via MD5/SHA1, que são criptograficamente quebrados.

**Mitigação sugerida:** Remover fallback MD5/SHA1 e forçar migração de senha (rehash com bcrypt/argon2 no próximo login).

### 7. Dependências Vulneráveis (parcialmente resolvido)

| Dependência | Antes | Agora | Status |
|------------|-------|-------|--------|
| `guzzlehttp/guzzle` | 6.5.8 | ^7.9 | ✅ Resolvido |
| `phpseclib/phpseclib` | 2.0.37 | ^3.0 | ✅ Resolvido |
| `symfony/debug` | v3.4.47 | `symfony/error-handler ^7.0` | ✅ Resolvido |
| `monolog/monolog` | 1.27.1 | ^3.0 | ✅ Resolvido |
| `klein/klein` | v2.1.2 | v2.1.2 | ⚠️ Pendente — deprecation warnings suprimidos, substituição por Slim 4 planejada |
| jQuery (frontend) | 3.3.1 | 3.3.1 | ⚠️ Pendente — atualizar para 3.7+ |

**Mitigação restante:** Substituir Klein por Slim 4 e atualizar jQuery.

### 8. Headers de Segurança HTTP (parcialmente resolvido)

Headers adicionados no `docker/apache-vhost.conf`:

- ✅ `Content-Security-Policy` — configurado
- ✅ `X-Frame-Options: DENY`
- ✅ `X-Content-Type-Options: nosniff`
- ✅ `Referrer-Policy: strict-origin-when-cross-origin`
- ✅ `X-XSS-Protection: 1; mode=block`
- ✅ `Permissions-Policy: camera=(), microphone=(), geolocation=()`
- ⚠️ `Strict-Transport-Security` (HSTS) — pendente (requer HTTPS configurado)

**Mitigação restante:** Adicionar HSTS quando HTTPS estiver ativo.

### 9. Geração de Tokens com Entropia Fraca

`uniqid()` (baseado em timestamp) e `mt_rand()` usados para tokens de segurança:

| Arquivo | Linha | Uso |
|---------|-------|-----|
| `lib/SP/Services/PublicLink/PublicLinkService.php` | 92 | `hash('sha256', uniqid('sysPassPublicLink', true))` |
| `lib/SP/Services/Backup/FileBackupService.php` | 100 | `sha1(uniqid('sysPassBackup', true))` |
| `lib/SP/Util/PasswordUtil.php` | 81 | `mt_rand(0, $alphaLength)` para geração de senhas |

**Mitigação sugerida:** Substituir por `random_bytes()` e `random_int()`.

---

## SEVERIDADE MÉDIA

### 10. SHA1 para Operações Sensíveis

SHA1 usado para derivação de chaves de sessão, cookies e links públicos:

- `lib/SP/Core/Crypt/SecureKeyCookie.php:74` — SHA1 de User-Agent + IP
- `lib/SP/Services/Crypt/SecureSessionService.php:147` — idem
- `lib/SP/Core/Crypt/Session.php:57` — SHA1 com session_id

**Mitigação sugerida:** Substituir SHA1 por SHA256 ou superior.

### 11. Chaves de Sessão Baseadas em Tempo

**Arquivo:** `lib/SP/Core/Context/SessionContext.php:288`

```php
return $this->setSecurityKey(sha1(time() . $salt));
```

Valor previsível baseado em timestamp.

**Mitigação sugerida:** Usar `random_bytes()` como fonte de entropia.

### 12. Session Regeneration Insuficiente

`session_regenerate_id()` encontrada em apenas 1 local (`lib/SP/Core/Crypt/Session.php:86`). Deveria ser chamada no login e em mudanças de privilégio para prevenir session fixation.

### 13. CSRF com Token Reutilizável

**Arquivo:** `lib/SP/Mvc/Controller/ControllerTrait.php:105-124`

O token de segurança (`sk`) é reutilizado entre requisições, permitindo replay attacks na mesma janela de sessão.

**Mitigação sugerida:** Implementar CSRF tokens únicos por requisição.

### 14. Exposição do Diretório .git

Se o web server não bloquear acesso, o `.git/` expõe todo o histórico de commits, credenciais e código fonte.

**Mitigação sugerida:** Bloquear acesso a `.git`, `composer.json`, `composer.lock` e diretórios não-públicos via configuração do web server (.htaccess ou nginx).

### 15. Command Injection no Backup

**Arquivo:** `lib/SP/Services/Backup/FileBackupService.php:388-389`

```php
$command = 'tar czf ' . $this->backupFileApp . '.tar.gz ' . BASE_PATH . ' --exclude "' . $this->path . '"';
exec($command, $resOut, $resBakApp);
```

Os valores atualmente vêm de constantes (risco baixo), mas o padrão é inseguro e quebrável em refatorações futuras.

**Mitigação sugerida:** Usar `escapeshellarg()` em todos os argumentos passados para `exec()`.

### 16. ~~mcrypt Deprecated~~ ✅ RESOLVIDO

~~Usa funções `mcrypt_*` removidas no PHP 7.1+.~~

**Resolvido em 2026-03-31:** `OldCrypt.php` reescrito com `openssl_encrypt/decrypt` + `random_bytes()`. Nota: aes-256-cbc não é 100% compatível com mcrypt RIJNDAEL-256 para dados muito antigos.

---

## SEVERIDADE BAIXA

### 17. Supressão de Erros com @

Chamadas LDAP usam `@` para suprimir erros (`@ldap_connect`, `@ldap_bind`, `@ldap_search` em `lib/SP/Providers/Auth/Ldap/`), dificultando detecção de problemas.

### 18. Cache com MD5

**Arquivo:** `lib/SP/Services/Account/AccountAclService.php:187`

Nomes de arquivo de cache ACL usam MD5 (colisões teóricas possíveis).

### 19. ini_set('auto_detect_line_endings', true)

**Arquivo:** `lib/SP/Services/Import/FileImport.php:159`

Diretiva deprecated desde PHP 8.1.

---

## Pontos Positivos Identificados

- A maioria dos repositórios usa **prepared statements** corretamente
- **LDAP injection** é mitigada com `ldap_escape()` em `LdapStd.php:129-130`
- **Upload de arquivos** valida MIME type contra whitelist
- Uso de **defuse/php-encryption** (v2.3.1) para criptografia moderna
- **roave/security-advisories** no require-dev para detectar dependências vulneráveis
- Sessão pode ser cifrada via `CryptSessionHandler`

---

## Priorização de Correções

| Prioridade | Ação | Itens | Status |
|-----------|------|-------|--------|
| **P0 — Imediato** | ~~Migrar para PHP 8.1+~~ | #1 | ✅ Resolvido |
| **P0 — Imediato** | ~~Corrigir `unserialize()` inseguro~~ | #2 | ✅ Resolvido |
| **P0 — Imediato** | ~~Adicionar proteção XXE~~ | #3 | ✅ Resolvido (LIBXML_NONET em todos os parsers) |
| **P1 — Urgente** | ~~Escapar saída em templates (XSS)~~ | #4 | ✅ Resolvido |
| **P1 — Urgente** | Remover fallback MD5/SHA1 | #6 | Pendente |
| **P1 — Urgente** | ~~Atualizar dependências~~ | #7 | ✅ Parcialmente resolvido (Klein e jQuery pendentes) |
| **P1 — Urgente** | ~~Corrigir SQL injection~~ | #5 | ✅ Resolvido (whitelist + prepared statements + regex) |
| **P2 — Importante** | ~~Adicionar headers HTTP de segurança~~ | #8 | ✅ Resolvido (HSTS pendente) |
| **P2 — Importante** | Substituir `uniqid()`/`mt_rand()` | #9 | Pendente |
| **P2 — Importante** | Implementar CSRF por requisição | #13 | Pendente |
| **P2 — Importante** | ~~Bloquear `.git` e configs no web server~~ | #14 | ✅ Resolvido (apache-vhost.conf) |
| **P3 — Desejável** | Substituir SHA1 por SHA256+ | #10 | Pendente |
| **P3 — Desejável** | Melhorar regeneração de session ID | #12 | Pendente |
| **P3 — Desejável** | Escapar argumentos de shell | #15 | Pendente |
| **P3 — Desejável** | ~~Remover código mcrypt legado~~ | #16 | ✅ Resolvido (OpenSSL) |

---

> **Nota:** Este é um gerenciador de senhas — cada vulnerabilidade tem impacto amplificado porque o sistema armazena credenciais sensíveis. O cenário mais preocupante é a combinação de **desserialização insegura + PHP EOL**, que pode dar a um atacante **execução remota de código** e acesso a todas as senhas armazenadas.
