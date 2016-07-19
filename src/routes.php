<?php
// Routes

function generateAuth($app){
    $key = $app->settings['secretKey'];
    return \Firebase\JWT\JWT::encode(['user' => 'unyleya'],$key,'HS512');
}

function authentication($auth,$app) {
    if(!$auth)
        return false;
    try{
        $jwt = \Firebase\JWT\JWT::decode($auth,$app->settings['secretKey'],array('HS512'));
        return $jwt;
    }catch(Exception $e) {
        return false;
    }
}
$app->get('/db', function($request, $response, $args){
    try{
        $db = getDB();
        echo 'Conectado com sucesso!';
    }   catch (PDOException $e) {
        return $e->getMessage();
    }
});

$app->post('/login', function ($request, $response, $args) {
    $body = $request->getParsedBody();
    $conta = $body['conta'];
    $senha = $body['senha'];
    if($conta == 'unyleya' && $senha == 'teste'){
        $newResponse = $response->withJson([generateAuth($this)],200);
        return $newResponse;
    } else {
        $newResponse = $response->withJson([],401);
        return $newResponse;
    }
});

$app->get('/modelos[/{id}]', function ($request, $response, $args) {
    // Sample log message
    $this->logger->info("Lista Modelos '/modelos[/{id}]' route");

    $auth = $request->getHeaderLine('HTTP_AUTHORIZATION');
    list($auth) = sscanf($auth,'Bearer %s');

    $jwt = authentication($auth,$this);
    if(!$jwt) {
        $newResponse = $response->withJson(array('body' => '','error' => array('error' => 1,'text' => 'Usuário não autorizado')),401);
        return $newResponse;
    }

    try {
        $id = $args['id'];
        $db = getDB();

        if($id) {
            $where = "WHERE m.id_modelo = $id";
        }

        $sql = <<<DML
            SELECT 
                m.id_modelo, 
                m.nome, 
                m.ano_modelo, 
                m.aro_roda, 
                GROUP_CONCAT(
                    CONCAT(
                        a.id_acessorio, '@',
                        a.nome, '@',
                        a.opcional
                    ) 
                SEPARATOR '@;@') AS acessorios
            FROM modelo m
            LEFT JOIN acessorio a ON (m.id_modelo = a.id_modelo)
            $where
            GROUP BY m.id_modelo, m.nome, m.ano_modelo, m.aro_roda
            ORDER BY m.id_modelo, a.id_acessorio 
DML;
        $stmt = $db->query($sql);
        $modelo = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if($modelo) {
            $db = null;
            foreach($modelo as &$item){
                if(strlen($item['acessorios']) > 1) {
                    $acessorios = explode('@;@',$item['acessorios']);
                    $item['acessorios'] = array();
                    foreach ($acessorios as $acessorio) {
                        $arFinal = explode('@',$acessorio);
                        $item['acessorios'][]= array('id_acessorio' => $arFinal[0],'nome' => $arFinal[1], 'opcional' => $arFinal[2]);
                    }
                }
            }
            $newResponse = $response->withJson(array('body' => $modelo,'error' => array('error' => 0,'text' => 'Sucesso!')),200);
            return $newResponse;
        } else {
            throw new PDOException('Nada encontrado.');
        }

    } catch(PDOException $e) {
        $newResponse = $response->withJson(array('body' => '','error' => array('error' => 1,'text' => $e->getMessage())),404);
        return $newResponse;
    }
});


$app->put('/modelo', function ($request, $response, $args) {
    // Sample log message
    $this->logger->info("Insere Modelo '/modelo' route");

    $auth = $request->getHeaderLine('HTTP_AUTHORIZATION');
    list($auth) = sscanf($auth,'Bearer %s');

    $jwt = authentication($auth,$this);
    if(!$jwt) {
        $newResponse = $response->withJson(array('body' => '','error' => array('error' => 1,'text' => 'Usuário não autorizado')),401);
        return $newResponse;
    }

    $params = $request->getParsedBody();
    $id_modelo = $params['id_modelo'];
    $nome = $params['nome'];
    $ano_modelo = $params['ano_modelo'];
    $aro_roda = $params['aro_roda'];

    $acessorios = array();
    $quantidade = count($params['nome_acessorio']);
    for($i = 0; $i < $quantidade; $i++){
        $acessorios[] = array('nome' => $params['nome_acessorio'][$i], 'opcional' => $params['opcional'][$i] == 'true' ? 1 : 0);
    }

    try {
        if(!$nome || !$ano_modelo || !$aro_roda) {
            throw new PDOException('Um dos parâmetros não foi preenchido!');
        }

        $db = getDB();
        if($quantidade > 0){
            $db->beginTransaction();
        }
        if($id_modelo) {
            $sql = <<<DML
            DELETE FROM acessorio WHERE id_modelo = :id_modelo;
DML;
            $stmt = $db->prepare($sql);
            $stmt->execute(array('id_modelo' => $id_modelo));
            $sql = <<<DML
            UPDATE modelo SET nome = :nome, ano_modelo = :ano_modelo, aro_roda = :aro_roda WHERE id_modelo = :id_modelo
DML;
            $stmt = $db->prepare($sql);
            $resultado = $stmt->execute(array('nome' => $nome,'ano_modelo' => $ano_modelo, 'aro_roda' => $aro_roda, 'id_modelo' => $id_modelo));
        } else {
            $sql = <<<DML
            INSERT INTO modelo (nome, ano_modelo, aro_roda) VALUES (:nome,:ano_modelo,:aro_roda)
DML;
            $stmt = $db->prepare($sql);
            $resultado = $stmt->execute(array('nome' => $nome,'ano_modelo' => $ano_modelo, 'aro_roda' => $aro_roda));
        }

        if($quantidade > 0 && $resultado) {
            $lastId = $id_modelo ?: $db->lastInsertId();
            for($i = 0; $i < $quantidade; $i++) {
                $sql = <<<DML
                   INSERT INTO acessorio (id_modelo, nome, opcional) VALUES (:id_modelo,:nome,:opcional)
DML;
                $stmt = $db->prepare($sql);
                $stmt->execute(array('id_modelo' => $lastId, 'nome' => $acessorios[$i]['nome'], 'opcional' => $acessorios[$i]['opcional']));
            }
            $resultado = $db->commit();
        }

        if($resultado) {
            $db = null;
            $newResponse = $response->withJson(array('body' => '','error' => array('error' => 0,'text' => 'Sucesso!')),200);
            return $newResponse;
        } else {
            if($quantidade > 0)
                $db->rollBack();
            throw new PDOException('Falha ao salvar.');
        }

    } catch(PDOException $e) {
        $newResponse = $response->withJson(array('body' => '','error' => array('error' => 1,'text' => $e->getMessage())),404);
        return $newResponse;
    }
});

$app->delete('/modelo', function ($request, $response, $args) {
    $this->logger->info("Deleta Modelo '/modelo' route");

    $auth = $request->getHeaderLine('HTTP_AUTHORIZATION');
    list($auth) = sscanf($auth,'Bearer %s');

    $jwt = authentication($auth,$this);
    if(!$jwt) {
        $newResponse = $response->withJson(array('body' => '','error' => array('error' => 1,'text' => 'Usuário não autorizado')),401);
        return $newResponse;
    }

    $params = $request->getParsedBody();
    $id_modelo = $params['id_modelo'];

    try {
        if(!$id_modelo) {
            throw new PDOException('Um dos parâmetros não foi preenchido!');
        }

        $db = getDB();
        $sql = <<<DML
            DELETE FROM modelo WHERE id_modelo = :id_modelo
DML;
        $stmt = $db->prepare($sql);
        $resultado = $stmt->execute(array('id_modelo' => $id_modelo));

        if($resultado) {
            $db = null;
            $newResponse = $response->withJson(array('body' => '','error' => array('error' => 0,'text' => 'Sucesso!')),200);
            return $newResponse;
        } else {
            throw new PDOException('Falha ao excluir.');
        }

    } catch(PDOException $e) {
        $newResponse = $response->withJson(array('body' => '','error' => array('error' => 1,'text' => $e->getMessage())),404);
        return $newResponse;
    }
});