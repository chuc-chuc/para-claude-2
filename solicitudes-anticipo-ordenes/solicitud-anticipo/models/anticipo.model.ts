// models/anticipo.model.ts

export interface DatosAnticipo {
    // Campos básicos
    id_solicitud?: string | number;
    tipo: 'efectivo' | 'cheque' | 'transferencia' | 'deposito'; // ✅ AGREGADO: deposito
    monto: number;
    comentario: string;
    id_orden: number;
    tipo_solicitud: number;

    // Metadatos
    numero_orden?: string | number;
    fecha_creacion?: string;
    nombre_estado?: string;
    id_estado?: number;

    // Campos específicos para EFECTIVO
    agencia?: string;
    mismo_usuario_recoge?: boolean;
    usuario_recoge?: string | number;
    nombre_usuario_recoge?: string;

    // Campos específicos para CHEQUE
    nombre_beneficiario?: string;
    consignacion?: string;
    no_negociable?: boolean;

    // Campos específicos para TRANSFERENCIA
    numero_cuenta?: string;
    banco?: string;
    tipo_cuenta?: string;
    nombre_cuenta?: string;

    // ✅ CAMPOS ESPECÍFICOS PARA DEPÓSITO (adaptados a tu backend)
    id_socio?: number;
    id_cuenta?: number; // Será el NumeroCuenta de tu backend
    nombre_socio?: string;

    // Archivos
    archivo_drive_id?: string;
    archivo_nombre?: string;
    archivo_excepcion_drive_id?: string;
    archivo_excepcion_nombre?: string;
    comprobante_drive_id?: string;
    comprobante_nombre?: string;
    tiene_archivo_excepcion?: boolean;
    tiene_comprobante_transferencia?: boolean;

    // Legacy
    concepto?: string;
    solicitante?: string;
    detalle?: string;
}

export interface OpcionSelector {
    id: string | number;
    nombre: string;
}

export interface Agencia extends OpcionSelector {
    id_agencia?: number;
}

export interface Banco extends OpcionSelector {
    id_banco?: number;
}

export interface TipoCuenta extends OpcionSelector {
    id_tipo_cuenta?: number;
}

export interface Usuario extends OpcionSelector {
    id_usuario?: string;
}

// ✅ INTERFACES EXACTAS SEGÚN TU BACKEND
export interface Socio {
    id_socio: number;
    nombre: string;
    numero_identificacion?: string;
    estado?: string;
    telefono?: string;
    email?: string;
}

export interface CuentaSocio {
    NumeroCuenta: number;  // ✅ Exacto como tu backend
    Producto: string;      // ✅ Exacto como tu backend: "114A.AHORRO.DISPONIBLE"
    Estado: string;        // ✅ Exacto como tu backend: "Activo"
}