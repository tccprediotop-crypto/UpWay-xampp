import secrets
import json
from datetime import datetime, timedelta
from flask import session, request
import pymysql

ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'

def gerar_token_seguro(tamanho=8):
    return ''.join(secrets.choice(ALPHABET) for _ in range(tamanho))

def obter_ip_atual():
    return request.remote_addr or '0.0.0.0'

def registrar_auditoria(conn, autor_id, acao, entidade, entidade_id, metadados=None):
    try:
        with conn.cursor() as cur:
            cur.execute("""
                INSERT INTO audit_logs (actor_user_id, action, entity_type, entity_id, metadata_json, ip_address)
                VALUES (%s,%s,%s,%s,%s,%s)
            """, (
                autor_id,
                acao,
                entidade,
                entidade_id,
                json.dumps(metadados or {}, ensure_ascii=False),
                obter_ip_atual()
            ))
    except Exception:
        pass

def formatar_status_pacote(status):
    mapeamento = {
        'RECEIVED': 'Recebida',
        'NOTIFIED': 'Notificada',
        'TOKEN_GENERATED': 'Token ativo',
        'PICKED_UP': 'Retirada'
    }
    return mapeamento.get(status, status)

def classe_status_pacote(status):
    mapeamento = {
        'RECEIVED': 'status-soft',
        'NOTIFIED': 'status-info',
        'TOKEN_GENERATED': 'status-warning',
        'PICKED_UP': 'status-success'
    }
    return mapeamento.get(status, 'status-soft')
