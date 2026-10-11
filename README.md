# Grid Finance

Projeto de gestão de despesas compartilhadas, baseado no teste técnico de Full Stack Pleno do Grupo Gridmidia. A aplicação permite cadastrar unidades, registrar despesas em BRL ou USD, dividir os valores entre as unidades, importar despesas por CSV e consultar os totais em reais.

## Como rodar

Usei Docker para manter PHP, MySQL, Nginx e o worker no mesmo ambiente. Para rodar dessa forma, precisa ter Docker com Compose v2 instalado. Execute os comandos dentro da pasta do projeto, usando Git Bash ou um terminal Linux/macOS.

Para clonar o projeto:

```sh
git clone https://github.com/VictorDS87/shared-expenses.git
cd shared-expenses
```

1 - Crie uma cópia do `.env.example` com o nome `.env`:

```sh
cp .env.example .env
```

No PowerShell, use `Copy-Item .env.example .env`.

2 - Construa as imagens. As dependências PHP e o build do frontend já são feitos nessa etapa:

```sh
docker compose build app web
```

3 - Gere a chave da aplicação:

```sh
docker compose run --rm --no-deps app php artisan key:generate --show
```

Copie o resultado para `APP_KEY` no `.env`. Esse comando mostra a chave, mas não altera o arquivo da sua máquina.

4 - Inicialize o banco e rode as migrations:

```sh
docker compose --profile setup run --rm init
```

5 - Carregue os dados de demonstração:

```sh
docker compose run --rm app php artisan db:seed
```

6 - Suba a aplicação e o worker:

```sh
docker compose up -d web worker
```

7 - Acesse [localhost:8080](http://localhost:8080).

```text
E-mail: demo@gridmidia.local
Senha: Grid@2026!
```

O seed cria um usuário, cinco unidades e despesas fictícias. Ele só roda em ambiente `local` ou `testing`. O e-mail e a senha podem ser configurados com `DEMO_EMAIL` e `DEMO_PASSWORD`.

A aplicação usa a porta `8080` e o MySQL fica disponível na `3307`, ambos apenas na máquina local. Para trocar, ajuste `APP_PORT` e `FORWARD_DB_PORT` no `.env`.

Comandos úteis:

```sh
docker compose ps
docker compose logs -f app worker
docker compose exec app php artisan queue:failed
docker compose exec app php artisan finance:status --json
docker compose down
```

O `down` mantém os dados. Adicionar `-v` apaga os volumes, incluindo o banco. Depois de alterar o código, rode novamente `docker compose build app web` e `docker compose up -d web worker`, pois os arquivos estão dentro das imagens.

### Rodar o PHP fora do Docker

Precisa de PHP 8.3 ou superior, Composer 2, Node.js 22, npm e MySQL 8.4. Além das extensões usuais do Laravel, habilite `bcmath`, `intl`, `pdo_mysql` e, para os testes rápidos, `pdo_sqlite`.

```sh
cp .env.example .env
composer install
npm ci
php artisan key:generate
```

Crie um banco vazio e configure `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME` e `DB_PASSWORD` no `.env`. Para usar apenas o banco do Docker, rode `docker compose up -d db` e configure `DB_HOST=127.0.0.1` e `DB_PORT=3307`. Ajuste também `APP_URL=http://localhost:8000`.

```sh
php artisan migrate --seed
npm run build
php artisan serve
```

Em outro terminal:

```sh
php artisan queue:work database --sleep=2 --tries=5 --timeout=60
```

Acesse [localhost:8000](http://localhost:8000). Para editar CSS e JavaScript com atualização automática, use `npm run dev` em outro terminal. No Windows, prefira Docker ou WSL para o worker ter suporte completo aos timeouts de processo.

## Decisões do projeto

- O foco foi atender ao fluxo do PDF: unidades, despesas, rateio, câmbio, importação e relatório. Os pontos que exigiram mais cuidado foram fechar os centavos, preservar a despesa quando o câmbio falha e retomar uma importação interrompida.
- Mantive um único projeto Laravel 13, com MySQL, Blade e Vite. Para as telas propostas, Blade e um pouco de JavaScript atendem ao necessário. Não vi necessidade de adicionar uma SPA ou novos serviços para esse escopo.
- Separei os controllers web dos controllers da API porque os retornos são diferentes: páginas e redirecionamentos na interface, JSON na API. As regras de cadastro são compartilhadas entre eles e a importação.
- Usei IA como apoio na implementação e na revisão do código.

### Valores e rateio

- Mantive o dinheiro em centavos inteiros. R$ 10,99 fica armazenado como `1099`. Os valores decimais recebidos são tratados como texto antes da conversão, para não depender de `float` nos cálculos financeiros.
- Fiz o mesmo com os percenuais: `33.34%` vira `3334`, e `100%` vira `10000`. Assim, a validação exige a soma exata, sem uma margem de tolerância escondida.
- Arredondar cada parcela separadamente pode fazer a soma ficar diferente da despesa. Por isso, o calculador primeiro separa a parte inteira de cada parcela e distribui os centavos restantes pelas maiores sobras. Se houver empate, o menor ID recebe primeiro.
- A validação aparece no cadastro e no calculador por motivos diferentes. O cadastro devolve erros dos campos para quem enviou os dados. O calculador protege sua própria regra, mesmo se for chamado por outro fluxo.
- Usei BCMath nos produtos intermediários e na conversão de câmbio. O total em reais é arredondado uma vez, pelo critério de meio para cima, e só depois é dividido entre as unidades.
- Três percentuais de `33.33%` somam `99.99%` e são rejeitados. Uma divisão possível é `33.34 / 33.33 / 33.33`. Uma opção de “partes iguais” precisaria de uma regra própria.

### Câmbio pela fila

- A despesa em BRL já fica convertida no cadastro. Em USD, mantive o valor original e deixei a conversão pendente para o worker. Uma falha na API de câmbio não impede registrar a despesa.
- A despesa, os rateios originais e o job são gravados na mesma transação. Isso evita salvar uma despesa pendente sem registrar o trabalho que vai convertê-la.
- Usei Frankfurter v2 com origem ECB explícita. A consulta considera a data da despesa e aceita a última cotação disponível até essa data, para atender dias sem cotação. Taxa, origem e data efetiva ficam salvas. Essa é uma referência para o teste; a regra de produção precisa ser confirmada com o negócio. A documentação está em [frankfurter.dev](https://frankfurter.dev/).
- O job faz até três tentativas, com intervalos de 10 e 60 segundos entre elas. O HTTP tem timeout de conexão de 3 segundos e resposta de 8 segundos. O job tem timeout de 20 segundos, abaixo dos 90 segundos de `retry_after`.
- A consulta externa acontece antes de bloquear a despesa. Ao receber a cotação, o job bloqueia o registro e confere novamente o estado e a versão antes de salvar. Isso evita consolidar duas vezes quando trabalhos concorrentes tentam converter a mesma despesa.
- Se as tentativas acabarem, a despesa fica com falha e pode ser reprocessada pela interface ou API. O reprocessamento cria uma nova versão, para um job antigo não sobrescrever a tentativa atual. Por isso, a recuperação deve usar essa ação, e não apenas `queue:retry`.
- Despesas pendentes ou com falha aparecem separadas no relatório, sem entrar como valores já consolidados em reais.

### Importação por CSV

- Mantive CSV em UTF-8, separador `;`, data `YYYY-MM-DD`, valor com ponto decimal e moeda `BRL` ou `USD`. O rateio usa o identificador da unidade seguido do percentual. O modelo pode ser baixado na tela.

```csv
data;descricao;fornecedor;valor;moeda;rateio
2026-09-01;Licença CRM;Fornecedor X;1500.00;USD;corporativo:50|fdr:30|adseleto:20
2026-09-02;Internet;Fornecedor Y;349.90;BRL;corporativo:50|fdr:50
```

- Os identificadores precisam existir no cadastro. O exemplo usa unidades do seed. Para importar uma planilha, exporte para CSV no formato do modelo.
- Cada linha usa a mesma regra de cadastro da interface e da API. Uma linha inválida não desfaz as válidas. O resultado mostra os erros por linha e o vínculo das linhas importadas com as despesas criadas.
- O upload aceita até 2 MiB. Antes de gravar, o importador confere o cabeçalho e o limite de 1.000 registros. Se passar desse limite, rejeita o arquivo inteiro.
- Reenviar exatamente o mesmo arquivo pelo mesmo usuário não cria novas despesas. Se a importação já terminou, registro o reenvio no histórico: zero linhas importadas, as anteriormente aceitas como ignoradas e os erros anteriores preservados, sem nova validação. As categorias não se sobrepõem. O resultado tem um link para a importação original. Se foi interrompida, continua das linhas ainda não confirmadas no lote original.
- A despesa e o avanço da linha processada são confirmados na mesma transação. Se o processo parar, a retomada continua das linhas ainda não confirmadas. O bloqueio por linha também coordena requisições concorrentes.
- Duas linhas com descrição, fornecedor e valor iguais podem gerar duas despesas, inclusive dentro do mesmo CSV, porque podem ser cobranças reais. Não há comparação desses campos para eliminar duplicatas. A proteção contra repetição se aplica ao arquivo inteiro enviado pelo mesmo usuário. Usuários diferentes geram importações independentes.
- Um arquivo alterado cria outra importação, então envie apenas as linhas corrigidas para não cadastrar novamente as que já foram aceitas. Ainda não há uma ação para repetir intencionalmente a importação de um arquivo idêntico pelo mesmo usuário.
- Guardei o arquivo original em base64 no banco para retomar sem novo upload, oculto das respostas da API. Base64 não é criptografia. O prazo de retenção precisa ser definido antes de produção.

### Relatório e acesso

- O relatório reúne totais por unidade e contagens por estado em uma consulta SQL única. Isso mantém uma leitura consistente quando uma conversão termina durante a consulta.
- A interface usa sessão, regeneração no login e proteção CSRF. A API usa tokens Sanctum com validade de oito horas e revogação. Os endpoints de negócio exigem autenticação e as validações ficam no backend.
- Os usuários têm acesso à operação financeira do grupo. Não há restrição de acesso por subsidiária ou níveis de aprovação.
- Usei a identidade visual do Grupo Gridmidia. A logo e o favicon vieram dos arquivos públicos do [site da empresa](https://gridmidia.com/).

## Onde estão as regras principais

| Arquivo | Responsabilidade |
| --- | --- |
| `app/Actions/CreateExpense.php` | Validação e gravação da despesa, rateios e job |
| `app/Domain/Money/DecimalMoney.php` | Valores decimais e cálculo monetário |
| `app/Domain/Expenses/AllocationCalculator.php` | Parcelas e distribuição dos centavos restantes |
| `app/Jobs/ConvertExpenseCurrency.php` | Conversão em segundo plano |
| `app/Services/Exchange/FrankfurterExchangeRateProvider.php` | Consulta da cotação externa |
| `app/Actions/RetryExpenseConversion.php` | Nova tentativa de conversão |
| `app/Services/CsvExpenseImporter.php` | Leitura, importação parcial e retomada |
| `app/Services/UnitReport.php` | Totais e estados do relatório |

## API

O prefixo é `/api/v1`. Envie `Accept: application/json` e, depois do login, `Authorization: Bearer SEU_TOKEN`.

| Método | Rota | Uso |
| --- | --- | --- |
| POST | `/auth/token` | Emitir token com e-mail e senha |
| DELETE | `/auth/token` | Revogar o token atual |
| GET / POST | `/units` | Listar ou cadastrar unidades |
| PUT | `/units/{id}` | Editar uma unidade |
| GET / POST | `/expenses` | Listar ou cadastrar despesas |
| GET | `/expenses/{id}` | Consultar despesa e rateios |
| POST | `/expenses/{id}/retry` | Reprocessar conversão com falha |
| POST | `/imports` | Enviar CSV no campo `file`, como multipart |
| GET | `/imports/{id}` | Consultar resultado |
| POST | `/imports/{id}/resume` | Retomar importação |
| GET | `/reports/units?from=YYYY-MM-DD&to=YYYY-MM-DD` | Relatório por período |

Exemplo de login no Git Bash ou terminal Linux/macOS:

```sh
curl -X POST http://localhost:8080/api/v1/auth/token \
  -H 'Accept: application/json' \
  -H 'Content-Type: application/json' \
  -d '{"email":"demo@gridmidia.local","password":"Grid@2026!","device_name":"teste-local"}'
```

O login retorna `201`, com este formato:

```json
{
  "token": "token-retornado-pela-api",
  "token_type": "Bearer",
  "expires_in": 28800
}
```

Copie o token para consultar as unidades:

```sh
TOKEN='token-retornado-pela-api'

curl http://localhost:8080/api/v1/units \
  -H 'Accept: application/json' \
  -H "Authorization: Bearer $TOKEN"
```

Exemplo do corpo de cadastro de despesa. Use os IDs retornados pela listagem de unidades:

```json
{
  "description": "Licença CRM",
  "supplier": "Fornecedor X",
  "date": "2026-09-01",
  "amount": "1500.00",
  "currency": "USD",
  "allocations": [
    { "unit_id": 1, "percentage": "50.00" },
    { "unit_id": 2, "percentage": "30.00" },
    { "unit_id": 3, "percentage": "20.00" }
  ]
}
```

Para cadastrar ou editar uma unidade, envie os três campos:

```json
{
  "name": "Comercial",
  "company": "Empresa de exemplo",
  "slug": "comercial"
}
```

O `slug` é único e identifica a unidade no CSV. Use letras minúsculas, números e hífens, sem hífen nas extremidades. O cadastro retorna `201` e a edição, `200`, com o registro dentro de `data`.

As despesas também vêm dentro de `data`. `amount` e `brl_amount` são textos decimais; `amount_minor` e `brl_amount_minor` são centavos inteiros. `status` pode ser `pending`, `converted` ou `failed`. Em USD, os campos BRL começam como `null`; consulte `GET /expenses/{id}` para acompanhar a conversão.

O valor e os percentuais devem ser enviados como strings, com ponto decimal e no máximo duas casas. O valor precisa ser positivo e no máximo `999999999.99`. A data precisa ser válida e não futura. O rateio aceita de uma a 100 unidades existentes, sem repetir a mesma unidade, com percentuais positivos e soma exata de 100%.

`GET /expenses` retorna `data`, `links` e `meta`, com 20 despesas por página. Aceita `page`, `per_page` de 1 a 100, `search`, `status`, `currency`, `from` e `to`. Na listagem, as datas são opcionais: `from` sozinho busca a partir daquela data, e `to` sozinho busca até aquela data. Quando ambos são enviados, a data final precisa ser maior ou igual à inicial. Os limites são inclusivos.

O relatório exige `from` e `to`, também com limites inclusivos e data final maior ou igual à inicial. Seus valores são retornados em centavos. Somente despesas convertidas entram no total. As contagens por unidade podem incluir a mesma despesa em mais de uma unidade.

Para importar um arquivo:

```sh
curl -X POST http://localhost:8080/api/v1/imports \
  -H 'Accept: application/json' \
  -H "Authorization: Bearer $TOKEN" \
  -F 'file=@despesas.csv'
```

A importação retorna `201`, inclusive no reenvio. Dentro de `data`, `imported_rows`, `ignored_rows` e `failed_rows` mostram os contadores exclusivos; sua soma corresponde a `total_rows`, que conta os registros já processados. No reenvio de um arquivo concluído, `original_import_id` identifica a importação original. `errors` contém `line` e `messages`, e `successful_rows` liga as linhas importadas neste lote a `expense_id`. O número da linha considera a posição física no arquivo, incluindo quebras de linha dentro de campos entre aspas. `status` pode ser `processing`, `interrupted` ou `completed`. O arquivo original não aparece na resposta.

`POST /imports/{id}/resume` retorna `200` com o lote atualizado. `POST /expenses/{id}/retry` só aceita USD com falha e retorna `202` com a despesa pendente. `DELETE /auth/token` retorna `204`, sem corpo, e revoga apenas o token utilizado. Um recurso inexistente retorna `404`.

A API retorna `401` sem autenticação válida, `422` para dados inválidos e `429` ao atingir os limites. Credenciais incorretas também retornam `422`. Envio e retomada de importações e reprocessamento de câmbio compartilham um limite de dez requisições por minuto por usuário, além do limite geral de 120 da API. Se receber `429`, aguarde o tempo indicado em `Retry-After`.

## Testes

- Os testes cobrem centavos, soma dos percentuais, validação compartilhada, importação parcial e retomada, autenticação e conversão com falhas, repetição e versões antigas.
- O provedor de câmbio é simulado nos testes, para o resultado não depender da internet nem da cotação do dia.
- Há testes de navegador com Playwright e testes com processos PHP concorrentes em MySQL. A concorrência exercita os bloqueios da conversão e da importação; não representa teste de carga nem toda a infraestrutura da fila.

Para verificar o estilo e os testes PHP:

```sh
composer check
```

Ou com a imagem Docker de desenvolvimento:

```sh
docker compose --profile tools run --rm --build check
```

O comando Docker usa SQLite em memória. A CI executa os testes PHP com MySQL 8.4, o build, os testes de navegador e os testes com processos concorrentes.

Para o navegador, com PHP, Composer e Node disponíveis e as dependências PHP instaladas:

```sh
npm ci
npx playwright install chromium
npm run build
npm run test:e2e
```

Essa suíte usa SQLite próprio e a porta `8091`, sem utilizar o MySQL da demonstração. Em falhas, salva screenshots e traces em `test-results` e o relatório em `playwright-report`.

Para o teste de concorrência, crie um banco MySQL exclusivo chamado `shared_expenses_concurrency_test` e dê ao usuário de teste permissão para criar e remover suas tabelas. O comando recria as tabelas desse banco; não use o banco da aplicação. No Git Bash ou terminal Linux/macOS, usando o MySQL local do Compose:

```sh
DB_CONNECTION=mysql \
DB_HOST=127.0.0.1 \
DB_PORT=3307 \
DB_DATABASE=shared_expenses_concurrency_test \
DB_USERNAME=grid \
DB_PASSWORD=grid_local_password \
php tests/Concurrency/run.php
```

Ajuste o usuário e a senha conforme seu ambiente. O script só aceita MySQL e um nome terminado em `_concurrency_test`. Ele inicia dois processos de conversão, verifica uma única confirmação e a consistência do relatório, e depois executa duas importações simultâneas do mesmo arquivo.

Para acompanhar problemas operacionais, use `php artisan finance:status --json` ou `php artisan finance:status --stale-minutes=10`. O comando aponta conversões pendentes, conversões antigas pendentes, conversões com falha, importações não concluídas e jobs falhados. Retorna código 0 quando não há itens que exigem atenção e 1 quando há conversões pendentes há mais tempo que o limite configurado, conversões com falha, importações não concluídas ou jobs falhados. Uma conversão pendente recente não altera o código de saída. Um valor inválido em `--stale-minutes` retorna código 2. Os logs identificam a despesa, a versão da conversão, a tentativa e o job; importações interrompidas registram o ID do lote.

Para conferir manualmente: cadastre BRL com rateio `33.34 / 33.33 / 33.33`, tente uma soma diferente de 100%, cadastre USD, importe o modelo CSV e confira as despesas e o relatório. Para converter USD, o worker precisa estar ativo.

## O que ficou fora

- Edição, exclusão e estorno de despesas. Antes disso, precisaria definir histórico, fechamento e correções retroativas.
- Aprovação, recorrência, anexos e integração ERP, que não foram necessários para atender ao teste.
- Arquivos grandes. A importação é síncrona e limitada; para volumes maiores, evoluiria para lotes pela fila com resultado consultável.
- Outras moedas. Mantive USD fixo no código da conversão porque o teste pede apenas BRL e USD. Para incluir outras moedas em um cenário real, faria o provedor receber a moeda de origem e centralizaria as moedas aceitas, reutilizando a fila e o rateio existentes.
- Spread e câmbio fiscal. Também falta definir uma operação para importar intencionalmente o mesmo arquivo de novo.
- Configurei logs, diagnóstico e healthchecks. Alertas, retenção dos arquivos, backups, HTTPS e configuração dos segredos ficaram pendentes para o ambiente de produção.

## Perguntas que faria ao negócio antes de produção

- A data da despesa é emissão, competência ou pagamento? Qual entra no relatório?
- Qual cotação a empresa usa? Como tratar feriados e taxas adicionais?
- Duas casas nos percentuais atendem? O desempate pelo menor ID faz sentido?
- Quem pode cadastrar, importar, corrigir e aprovar? Cada subsidiária pode ver os dados das outras?
- O fechamento mensal bloqueia alterações? Como corrigir um período já fechado?
- Existe número de documento ou identificador externo para reconhecer a mesma cobrança em arquivos diferentes?
- Câmbio pendente impede o fechamento? Quem recebe o aviso de falha e em quanto tempo precisa resolver?
- Uma unidade pode mudar de empresa ou identificador? O relatório precisa preservar as informações da época?
- Qual volume de despesas é esperado? Por quanto tempo guardar CSVs, logs e auditoria?
