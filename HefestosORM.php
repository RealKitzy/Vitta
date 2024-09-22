<?php


/**
* Responsável pela conexão, montagem e execução de queries no banco de dados.
* @author brunoggdev
*/
class HefestosORM
{
    protected static ?self $instancia = null;
    protected ?PDO $conexao;
    protected ?PDOStatement $query_info;
    protected string $tabela;
    protected string $query = '';
    protected array $params = [];
    protected string $classe_de_retorno;
    protected bool $checar_nome_tabela = true;
    protected bool $como_array = true;
    protected int $fetch_mode = PDO::FETCH_ASSOC;

    /**
     * Busca o array de conexão com o banco de dados e instancia o PDO.
     * Pode receber uma conexão alternativa na forma de um array 
     * com as mesmas chaves do padrão na pasta config.
     * @author brunoggdev
    */
    public function __construct(array $db_config)
    {
        [$dsn, $usuario, $senha] = $this->formatarConexao($db_config);

        $this->conexao = new PDO($dsn, $usuario, $senha, [
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);  
    }




    /**
     * Formata as informações de conexão com o banco, retornando o dsn, usuario e senha
     * @author Brunoggdev
    */
    private function formatarConexao(array $config):array
    {
        $dsn = $config['driver'] == 'mysql'
            ? "mysql:host=$config[host];dbname=$config[nome_db]"
            : 'sqlite:' . $config['sqlite'];

        return [$dsn, $config['usuario']??null, $config['senha']??null];
    }




    /**
     * Retorna a conexão ativa do banco de dados (singleton).
     * @param array $config Array associativo com as chaves 'driver' (sqlite ou mysql),
     * 'usuario', 'senha', 'host' e 'nome_db' se for mysql ou, caso contrário, 'sqlite' com o caminho do arquivo.
     * @author Brunoggdev
    */
    public static function instancia(?array $config = null):self
    {
        if (!is_null(self::$instancia)) {
            self::$instancia->tabela('');

            return self::$instancia;
        }

        if (is_null($config)) {
            throw new Exception('Configurações de conexão ao banco de dados não recebidas...');
        }

        self::$instancia = new self($config);

        return self::$instancia;
    }




    /**
     * Fecha a conexão com o banco de dados.
     * @author Brunoggdev
    */
    public function fechar():void
    {
        $this->conexao = null;
        $this->query_info = null;
    }




    /**
     * Define a tabela na qual o as próximas consultas serão executadas
    */
    public function tabela(string $tabela): self
    {
        $this->tabela = $tabela;

        return $this;
    }




    /**
    * Adiciona um SELECT na consulta
    * @author brunoggdev
    */
    public function select(array $colunas = ['*']): self
    {
        $colunas = implode(', ', $colunas);

        $this->query = "SELECT $colunas FROM $this->tabela";

        return $this;
    }




    /**
     * Adiciona um INSERT na consulta. 
     * Se informado um objeto como parâmetro ele será convertido para array.
     * Retorna o id inserido (por padrão) ou um bool para sucesso ou falha.
     * @author brunoggdev
    */
    public function insert(array|object $params, bool $retornar_id = true):string|bool
    {
        $this->params = (array) $params;

        $colunas = implode(', ', array_keys($this->params));
        $valores = ':' . implode(', :', array_keys($this->params));

        $this->query = "INSERT INTO $this->tabela ($colunas) VALUES($valores)";

        $resultado = $this->executarQuery();

        return $retornar_id ? $this->idInserido() : $resultado;
    }
   
   

   
    /**
     * Cria uma sql para DELETE
     * @return bool true se sucesso, false caso contrário;
     * @author Brunoggdev
    */
    public function delete(array|string $where):bool
    {

        $this->query = "DELETE FROM $this->tabela";
        $this->where($where);


        return $this->executarQuery();
    }




    /**
    * Adiciona um UPDATE na consulta
    * @author brunoggdev
    */
    public function update(array|object $params, array $where = []): bool
    {
        $this->params = (array) $params;
    
        $novos_valores = implode(', ', array_map(fn($key) => "$key = :$key", array_keys($params)));
    
        $this->query = "UPDATE $this->tabela SET $novos_valores";
        $this->where($where);
        
        return $this->executarQuery();
    }




    /**
    * Adiciona um WHERE na consulta
    * @param array|string $params string ou array associativo
    * @example $params ['id' => '2'] equals: id = 2 in the sql
    * @example $params ['id >=' => '1'] equals: id >= 1 in the sql
    * @author brunoggdev
    */
    public function where(array|string $params): self
    {
        if (empty($params)) {
            return $this;
        }

        if (empty($this->query)) {
            $this->select();
        }

        if (! str_contains($this->query, 'WHERE') ) {
            $this->query .= ' WHERE ';
        }

        if(is_string($params)){
            $this->query .= $params;
            return $this;
        }

        foreach ($params as $key => $value) {
            // retirando pontos pois não são aceitos nas chaves de array
            $chave = str_replace('.', '', $key);
            
            // Assume "=" caso nenhum operador seja informado no valor
            if(!preg_match('/(=|<|>|<=|>=|like)$/i', $chave)){
                $this->params[] = $value;
                $this->query .= "$key = ? ";
            }else{
                $this->params[] = $value;
                $this->query .= "$chave ? ";
            }
            
            if($key !== array_key_last($params)){
                $this->query .= 'AND ';
            }

        }

        return $this;
    }




    /**
    * Adiciona um OR na consulta e em seguida um where novamente
    * @author Brunoggdev
    */
    public function orWhere(array $params):self
    {
        $this->query .= ' OR ';
        $this->where($params);
        
        return $this;
    }




    /**
    * Adiciona um JOIN na consulta
    * @author Brunoggdev
    */
    public function join(string $tabela_para_join, string $condicao, ?string $tipo_de_join = 'INNER'):self
    {
        $this->query .= " $tipo_de_join JOIN $tabela_para_join ON $condicao";

        return $this;
    }




    /**
    * Adiciona um ORDER BY na query
    * @author brunoggdev
    */
    public function orderBy(string $column, string $order = 'ASC'):self
    {
        $this->query .= "ORDER BY $column $order ";
        
        return $this;
    }




    /**
     * Recebe uma sql completa para consultar no banco de dados.
     * Se informado um objeto como parâmetro ele será convertido para array.
     * @example $sql SELECT * FROM users WHERE id >= :id
     * @example $params ['id' => 1]
     * @return bool|PDOStatement false em caso de falha ou PDOStatement em caso de sucesso (que avalila para true)
     * @author brunoggdev
    */
    public function executar(string $sql, array|object $params = []): bool|PDOStatement
    {
        $this->query = $sql;
        $this->params = (array) $params;
        $this->checar_nome_tabela = false;

        if (!$this->executarQuery()) {
            return false;
        }

        return $this->query_info;
    }




    /**
     * Pega o primeiro resultado da consulta, podendo retornar uma coluna especifica
     * @author brunoggdev
    */
    public function primeiro(?string $coluna = null): mixed
    {
        $resultado = $this->executarQuery(true)->fetch($this->fetch_mode);

        if($coluna){
            return $resultado[$coluna] ?? null;
        }

        return $this->como_array ? $resultado : $this->retornarObjeto($resultado);
    }




    /**
     * Retorna todos os resultados da consulta montada até agora.
     * @param bool $coluna_unica retorna diretamente os valores da coluna sendo buscada
     * @example $coluna_unica $db->tabela('pets')->select('nome')->todos(true);  //retorna diretamente um array com todos os nomes
     * @author brunoggdev
    */
    public function todos(bool $coluna_unica = false): array
    {
        if (empty($this->query)) {
            $this->select(['*']);
        }

        $fetch_mode = $coluna_unica ? PDO::FETCH_COLUMN : $this->fetch_mode;
        $resultado = $this->executarQuery(true)->fetchAll($fetch_mode);

        return $this->como_array ? $resultado : $this->retornarObjeto($resultado, true);
    }



    /**
     * Retorna o resultado da consulta no formato do objeto definido
    */
    public function retornarObjeto(array $resultado, bool $todos = false):mixed
    {

        $classe = $this->classe_de_retorno;

        if ($todos) {
            return array_map(fn($resultado) => new $classe($resultado), $resultado);
        }

        return new $classe($resultado);
    }



    /**
     * Executa a sql no banco de dados e retorna o boolean do resultado ou,
     * opcionalmente, o PDOStatement;
     * @author brunoggdev
    */
    protected function executarQuery(bool $retornar_query = false):bool|PDOStatement
    {

        if (empty($this->tabela) && $this->checar_nome_tabela) {
            throw new Exception('Não foi definida a tabela onde deve ser realizada a consulta.');
        }

        // resetando a checagem sempre que esta função for chamada
        $this->checar_nome_tabela = true;

        $query = $this->conexao->prepare($this->query);
        
        $this->query_info = $query;
        
        $resultado = $query->execute($this->params);

        $this->query_info = $query;
        $this->query = '';
        $this->params = [];

        return $retornar_query ? $query : $resultado;
    }




    /**
    * Retorna a string montada da consulta
    * @author brunoggdev
    */
    public function stringDaConsultaSql():string
    {
        return $this->query;
    }




    /**
     * Retorna o número de linhas afetadas pela ultima sql
     * @author Brunoggdev
    */
    public function linhasAfetadas():int
    {
        return $this->query_info->rowCount();
    }




    /**
     * Retorna o último id inserido pela sql mais recente
     * @author Brunoggdev
    */
    public function idInserido():string|false
    {
        return $this->conexao->lastInsertId();
    }




    /**
    * Retorna os erros que ocorreram durante a execução da SQL
    * @author brunoggdev
    */
    public function erros():array
    {
        return $this->query_info->errorInfo();
    }




    /**
    * Define o retorno do banco de dados como um array associativo
    * @author Brunoggdev
    */
    public function comoArray():self
    {
        $this->como_array = true;

        return $this;
    }

    


    /**
     * Define o retorno do banco de dados como um objeto (ou array de objetos) da classe informada;
     * O array de resultados será passado para o construtor da classe desejada.
     * @param string $classe SuaClasse::class - O "nome qualificado" da classe desejada
     * @author Brunoggdev
    */
    public function comoObjeto(string $classe):self
    {
        $this->como_array = false;
        $this->classe_de_retorno = $classe;

        return $this;
    }




    /**
    * Define o fetch mode do PDO
    * @author Brunoggdev
    */
    public function fetchMode(int $fetch_mode):self
    {
        $this->fetch_mode = $fetch_mode;

        return $this;
    }



    /**
     * Retorna da tabela desejada a linha (ou coluna especifica) com o id informado, podendo retornar uma coluna especifica
     * @author Brunoggdev
    */
    public function buscar(int|string $id, ?string $coluna = null):mixed
    {
        return $this->primeiroOnde(['id' => $id], $coluna);
    }



    /**
     * Retorna o primeiro resultado para o 'where' informado, podendo retornar uma coluna especifica
    */
    public function primeiroOnde(array|string $where, ?string $coluna = null):mixed
    {
        return $this->where($where)->primeiro($coluna);
    }
}
