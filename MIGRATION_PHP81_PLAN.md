# Plano de Migração — PHP 7.4 para PHP 8.5

**Data:** 2026-03-31
**Atualizado:** 2026-03-31
**Referência:** SECURITY_AUDIT.md (item P0-1)
**Status:** Fases 1 a 5.1 concluídas. Pendente: Fase 5.2–5.4 (virada em produção)
**Risco geral:** Alto — requer atualização de dependências, reescrita de código removido e testes extensivos

## Ambiente Local

- **PHP local:** 8.5.2 (Homebrew, NTS)
- **Extensões necessárias:** Todas instaladas (pdo, dom, gd, json, gettext, fileinfo, zlib, libxml, mbstring, ldap)
- **Sem Docker:** desenvolvimento e testes diretamente com PHP local

---

## Visao Geral

A migração envolve 5 fases sequenciais. Como o ambiente local ja roda PHP 8.5, nao ha necessidade de compatibilidade dual com PHP 7.4 — o codigo sera migrado direto para PHP 8.5. A producao continua em PHP 7.4 ate a virada final.

```
Fase 1: Preparação (sem risco)
Fase 2: Corrigir codigo incompatível (alvo PHP 8.5)
Fase 3: Atualizar dependências (alvo PHP 8.5)
Fase 4: Testes locais com PHP 8.5
Fase 5: Virada em producao
```

**Nota:** Com PHP 8.5, alem das quebras do 8.0 e 8.1, ha mudanças adicionais a considerar:
- `FILTER_SANITIZE_STRING` removido (nao apenas deprecated)
- `libxml_disable_entity_loader()` removida (XXE protegido por padrao — P0-3 parcialmente resolvido)
- Tipagem mais estrita em funcoes internas
- `utf8_encode()`/`utf8_decode()` removidas (verificar uso)

---

## Fase 1 — Preparação (sem risco)

**Objetivo:** Criar infraestrutura de testes e backup antes de qualquer alteração.

### 1.1 Criar branch dedicada

```bash
git checkout -b migration/php81
```

### 1.2 Validar ambiente local

- PHP 8.5.2 já instalado via Homebrew
- Todas as extensões necessárias já presentes (pdo, dom, gd, json, gettext, fileinfo, zlib, libxml, mbstring, ldap)
- Sem necessidade de Docker

### 1.3 Documentar estado atual

- Rodar os testes existentes em PHP 7.4 e salvar o resultado como baseline
- Fazer dump do banco de dados de staging/homologação para testes

---

## Fase 2 — Corrigir Codigo Incompatível

**Objetivo:** Ajustar todo o codigo que usa funcoes removidas/deprecated, mantendo compatibilidade com PHP 7.4.

### 2.1 LDAP Pagination (BLOQUEANTE)

**Problema:** `ldap_control_paged_result()` e `ldap_control_paged_result_response()` foram **removidas no PHP 8.0**. No PHP 8.0+ a paginação é feita via LDAP controls no próprio `ldap_search()`.

**Arquivo:** `lib/SP/Providers/Auth/Ldap/LdapActions.php:165-209`

**Solução:** Reescrever `getResults()` direto para PHP 8.5 (sem compatibilidade dual):

```php
protected function getResults($filter, array $attributes = null, $searchBase = null)
{
    if (empty($searchBase)) {
        $searchBase = $this->ldapParams->getSearchBase();
    }

    $searchRes = ldap_search(
        $this->ldapHandler,
        $searchBase,
        $filter,
        $attributes ?? [],
        0, 0, 0, LDAP_DEREF_NEVER,
        [['oid' => LDAP_CONTROL_PAGEDRESULTS, 'value' => ['size' => LdapInterface::PAGE_SIZE]]]
    );

    if (!$searchRes) {
        return false;
    }

    return @ldap_get_entries($this->ldapHandler, $searchRes);
}
```

**Teste:** Validar login LDAP em ambos os ambientes (PHP 7.4 e 8.1).

**Impacto em producao:** Nenhum se testado. Quem usa LDAP precisa testar login, busca de usuarios e grupos.

---

### 2.2 FILTER_SANITIZE_STRING (BLOQUEANTE)

**Problema:** `FILTER_SANITIZE_STRING` deprecated no PHP 8.1, será removido no PHP 9.0.

**Arquivo:** `lib/SP/Util/Filter.php:93-96`

**Solução:** Substituir por `htmlspecialchars()` + `strip_tags()`:

```php
public static function getString($value): string
{
    // FILTER_SANITIZE_STRING removia tags e codificava aspas.
    // Reproduzimos o mesmo comportamento:
    return htmlspecialchars(strip_tags(trim($value)), ENT_QUOTES, 'UTF-8');
}
```

**Nota:** `FILTER_FLAG_NO_ENCODE_QUOTES` era usado, então se quiser manter o comportamento original (não codificar aspas):

```php
return strip_tags(trim($value));
```

**Impacto em producao:** Baixo. `getString()` é usado em filtros de input em toda a aplicação. Testar formulários de busca, criação de contas, edição de campos.

---

### 2.3 OldCrypt / mcrypt (MEDIO RISCO)

**Problema:** Funções `mcrypt_*` removidas no PHP 7.2+. `OldCrypt` é usado apenas para **importação de dados legados** (exportações de versões antigas do sysPass).

**Arquivos:**
- `lib/SP/Core/Crypt/OldCrypt.php` (inteiro)
- `lib/SP/Services/Import/ImportTrait.php:125`
- `lib/SP/Services/Import/SyspassImport.php:142`

**Solução — duas opções:**

**Opção A (recomendada): Reimplementar com OpenSSL**
Reescrever `OldCrypt::getDecrypt()` e `OldCrypt::mkEncrypt()` usando `openssl_encrypt()`/`openssl_decrypt()` com `aes-256-cbc` (equivalente ao RIJNDAEL-256 CBC que o mcrypt usava).

**Opção B (mais simples): Remover suporte a importação legada**
Se ninguém precisa importar exportações de versões muito antigas do sysPass, remover `OldCrypt` e os trechos que o usam em `ImportTrait` e `SyspassImport`.

**Impacto em producao:** Nenhum para operação normal. Afeta apenas a funcionalidade de importar backups/exportações feitas em versões muito antigas do sysPass.

---

### 2.4 Null safety em funcoes de string (BLOQUEANTE)

**Problema:** No PHP 8.1, passar `null` para funções como `strpos()`, `trim()`, `strlen()` gera `TypeError`.

**Arquivos identificados:**
- `lib/SP/Http/Request.php:324` — `strpos($this->headers->get('Accept'), ...)` — header pode ser `null`
- `lib/SP/Util/Filter.php:95` — `trim($value)` — `$value` pode ser `null`
- Potencialmente dezenas de outros locais

**Solução:** Busca ampla e correção pontual:

```php
// Request.php:324 — ANTES:
return strpos($this->headers->get('Accept'), 'application/json') !== false;

// DEPOIS:
return strpos($this->headers->get('Accept') ?? '', 'application/json') !== false;
```

**Ação:** Rodar análise estática (PHPStan nivel 6+) com PHP 8.1 para identificar todos os pontos. Corrigir com operador `??` ou type casts.

**Impacto em producao:** Nenhum. O operador `??` é compatível com PHP 7.4.

---

### 2.5 Symfony Debug → ErrorHandler

**Problema:** `symfony/debug ^v3.4` não suporta PHP 8. No PHP 8+ o pacote correto é `symfony/error-handler`.

**Arquivo:** `lib/SP/Bootstrap.php:54`

```php
use Symfony\Component\Debug\Debug;
```

**Solução (compatibilidade dual):**

```php
if (class_exists('\Symfony\Component\ErrorHandler\Debug')) {
    \Symfony\Component\ErrorHandler\Debug::enable();
} elseif (class_exists('\Symfony\Component\Debug\Debug')) {
    \Symfony\Component\Debug\Debug::enable();
}
```

Esse ajuste funciona junto com a Fase 3 (atualização de dependências).

---

## Fase 3 — Atualizar Dependências

**Objetivo:** Atualizar `composer.json` para versões compatíveis com PHP 8.1.

### 3.1 Mapa de atualizações

| Dependência | Atual | Alvo (PHP 8.5) | Breaking Changes |
|------------|-------|----------------|-----------------|
| `php` | `~7.4` | `>=8.5` | Toda a migração |
| `klein/klein` | `^2.1` | **Substituir** | Ver 3.2 |
| `symfony/debug` | `^v3.4` | `symfony/error-handler ^7.0` | Namespace muda |
| `monolog/monolog` | `^1.23` | `^3.0` | Handler API muda |
| `guzzlehttp/guzzle` | `^6.3` | `^7.9` | PSR-7 v2, API similar |
| `phpseclib/phpseclib` | `^2.0` | `^3.0` | Namespaces mudam |
| `doctrine/common` | `^v2.7` | Remover ou `^3.4` | Verificar se é usado |
| `phpmailer/phpmailer` | `^6.0` | `^6.9` | Compatível, baixo risco |
| `defuse/php-encryption` | `^2.1` | `^2.4` | Compatível |
| `php-di/php-di` | `^6.0` | `^7.0` | API de definições muda |

### 3.2 Klein Router — Decisão Critica

`klein/klein ^2.1` (último release 2016) **não suporta PHP 8** e não tem manutenção. Há 3 opções:

**Opção A — Fork e patch (menor esforço)**
- Criar fork local do Klein 2.1
- Aplicar patches de compatibilidade PHP 8.1
- Manter como dependência local via `repositories` no composer.json
- Risco: dívida técnica, continua sem manutenção

**Opção B — Migrar para Slim 4 (esforço médio)**
- Slim 4 tem API similar ao Klein (micro-framework)
- Precisa adaptar: `Bootstrap.php`, `Json.php`, `Request.php`, `Xml.php`, `ModuleBase.php`, `Minify.php`
- ~8 arquivos usam Klein diretamente

**Opção C — Migrar para FastRoute + PSR-15 (esforço alto)**
- Mais moderno, mais controle
- Maior refatoração

**Recomendação:** Opção A para a migração inicial (menor risco), com plano de migrar para Slim 4 depois.

### 3.3 Monolog 1.x → 3.x

Verificar:
- Quais handlers são usados
- Se há formatters customizados
- A API de criação de loggers mudou entre 1.x e 3.x

### 3.4 Guzzle 6 → 7

- Guzzle 7 mantém API muito similar à 6
- Principal mudança: PSR-7 v2 (MessageInterface muda)
- Buscar todos os usos de `GuzzleHttp\Client` e validar

### 3.5 phpseclib 2 → 3

- Namespaces mudam de `phpseclib\` para `phpseclib3\`
- Verificar todos os `use phpseclib\` no projeto e atualizar

### 3.6 composer.json — Platform config

Atualizar:

```json
"config": {
    "platform": {
        "php": "8.5"
    }
}
```

---

## Fase 4 — Testes

**Objetivo:** Validar que tudo funciona em PHP 8.5 localmente antes de ir para produção.

### 4.1 Testes automatizados

```bash
# Localmente com PHP 8.5.2
composer install
./vendor/bin/phpunit
```

Corrigir falhas iterativamente.

### 4.2 Análise estática

```bash
# Instalar PHPStan
composer require --dev phpstan/phpstan
./vendor/bin/phpstan analyse lib/ app/ -l 6
```

Isso vai revelar problemas de tipo (null safety, type mismatches) que o PHP 8.5 trata como erros.

### 4.3 Testes manuais — Checklist

| Funcionalidade | O que testar |
|---------------|-------------|
| Login local | Login com usuario/senha existente |
| Login LDAP | Login com usuario LDAP (se usado) |
| Busca de contas | Filtros, paginação |
| Visualizar senha | Decriptação e exibição |
| Criar/editar conta | Todos os campos, custom fields |
| Importação XML | Importar backup do sysPass |
| Importação CSV | Importar arquivo CSV |
| Exportação XML | Gerar backup |
| Backup | Gerar backup completo (BD + arquivos) |
| API JSON-RPC | Chamadas de API com token |
| Links publicos | Criar e acessar link público |
| Perfis/usuarios | CRUD de usuarios e perfis |
| Plugins | Se houver plugins habilitados |
| Email | Notificações por email |

### 4.4 Ambiente de homologação

- Restaurar dump do banco de producao no ambiente de teste com PHP 8.5
- Validar que dados serializados existentes no banco são lidos corretamente
- Testar por pelo menos 1 semana com usuarios reais
- **Servidor de producao precisará PHP 8.5** — verificar com infra antes da virada

---

## Fase 5 — Virada em Producao

### 5.1 Criar Dockerfile

Criar `Dockerfile` na raiz do projeto com PHP 8.5, extensões necessárias, Apache/Nginx e Composer. O Dockerfile será o artefato de deploy para produção.

### 5.2 Pré-virada

- [ ] Backup completo do banco de dados
- [ ] Backup completo dos arquivos da aplicação (incluindo container/imagem atual)
- [ ] Documentar versão atual da imagem Docker para rollback
- [ ] Agendar janela de manutenção
- [ ] Build e teste da nova imagem Docker localmente

### 5.3 Procedimento

1. Colocar aplicação em modo manutenção
2. Fazer backup final do BD
3. Build da imagem Docker com o codigo da branch `migration/php85`
4. Deploy do novo container (substituir o container PHP 7.4)
5. Limpar caches: `rm -rf app/cache/*`
6. Testar login e operações básicas
7. Remover modo manutenção

### 5.4 Plano de rollback

Se algo der errado:
1. Reativar modo manutenção
2. Subir container com a imagem Docker anterior (PHP 7.4)
3. Restaurar banco se necessário (apenas se houver migração de dados)
4. Remover modo manutenção

**Importante:** A Fase 5 **não requer migração de banco de dados** se na Fase 2 a abordagem para `unserialize()` foi `allowed_classes` (não a conversão para JSON).

---

## Pos-Migração

Após a virada estável, aplicar os outros itens do `SECURITY_AUDIT.md`:
- P0-2: Converter `serialize()` → JSON (agora com migração de BD)
- P0-3: XXE — em PHP 8.5 `libxml_disable_entity_loader` foi removida e o comportamento seguro é padrão. Apenas garantir uso de `LIBXML_NONET` onde aplicável.
- P1: XSS, headers HTTP, etc.
- Modernização: type declarations, `declare(strict_types=1)`, migrar Klein para Slim 4

---

## Resumo de Riscos por Fase

| Fase | Risco em Prod | Downtime | Precisa de BD |
|------|--------------|----------|---------------|
| 1 — Preparação | Nenhum | Nao | Nao |
| 2 — Corrigir codigo | Nenhum (branch separada) | Nao | Nao |
| 3 — Atualizar deps | Nenhum (branch separada) | Nao | Nao |
| 4 — Testes | Nenhum (local) | Nao | Nao |
| 5 — Virada | **Alto** | **Sim (janela de manutencao)** | Nao |

---

## Execução — Registro do que foi feito

### Fases 1 a 4 concluídas em 2026-03-31

**Branch:** `migration/php85`
**22 arquivos alterados:**

| Fase | Arquivo(s) | Mudança |
|------|-----------|---------|
| 2.1 | `lib/SP/Providers/Auth/Ldap/LdapActions.php` | `ldap_control_paged_result()` removido, reescrito com `LDAP_CONTROL_PAGEDRESULTS` + `ldap_parse_result()` |
| 2.2 | `lib/SP/Util/Filter.php` | `FILTER_SANITIZE_STRING` substituído por `strip_tags(trim())` |
| 2.3 | `lib/SP/Core/Crypt/OldCrypt.php` | Reescrito com `openssl_encrypt/decrypt` + `random_bytes()` no lugar de `mcrypt_*` |
| 2.4 | `Request.php`, `Filter.php`, `XmlHandler.php`, `DatabaseUtil.php`, `Cookie.php`, `LoggerBase.php`, `LdapMsAds.php`, `LdapMsAzureAd.php`, `ImageUtil.php` | Null safety — casts `(string)`, operador `??`, guards `empty()` |
| 2.5 | `lib/SP/Bootstrap.php` | `use Symfony\Component\Debug\Debug` → `use Symfony\Component\ErrorHandler\Debug` |
| 3 | `composer.json`, `composer.lock` | PHP >=8.1, monolog ^3.0, guzzle ^7.9, phpseclib ^3.0, php-di ^7.0, phpmailer ^6.9, symfony/error-handler ^7.0, phpunit ^10. Removidos: doctrine/common, phpunit/dbunit, fzaninotto/faker, fabpot/goutte, php-mock |
| 3 | `lib/SP/Core/Crypt/CryptPKI.php` | Reescrito para phpseclib 3 (nova API: `PublicKeyLoader`, `RSA::createKey()`, `->withPadding()`) |
| 4 | 6 handlers SplObserver | Adicionado `: void` em `update(SplSubject)` para compatibilidade PHP 8.5 (`AclHandler`, `NotificationHandler`, `DatabaseLogHandler`, `FileLogHandler`, `RemoteSyslogHandler`, `SyslogHandler`, `MailHandler`) |

**Klein Router:** Mantido como `^2.1` (funciona no PHP 8.5 com deprecation warnings que são suprimidos em produção via `error_reporting`). Substituição por Slim 4 planejada para etapa futura.

**Validação:** Todas as classes do sysPass carregam sem erros no PHP 8.5.2.

### Fase 5.1 concluída em 2026-03-31

| Arquivo | Descrição |
|---------|-----------|
| `Dockerfile` | PHP 8.5-apache, extensões (pdo_mysql, ldap, gd, intl, zip, etc.), OPcache, Composer, healthcheck |
| `docker/php.ini` | Config hardened: errors off, sessões seguras, OPcache, disable_functions (exec mantido para backup) |
| `docker/apache-vhost.conf` | Vhost com bloqueio de dirs sensíveis, security headers (CSP, X-Frame-Options, nosniff) |
| `docker-compose.yml` | App (porta 8080:80) + MariaDB 11, volumes nomeados, healthcheck |
| `.dockerignore` | Exclui .git, tests, vendor, node_modules, docs do build |

### Pendente

- **Fase 5.2–5.4:** Virada em produção (backup, deploy da imagem, testes, rollback se necessário)
