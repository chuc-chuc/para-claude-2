import { Component, OnInit, Input, Output, EventEmitter } from '@angular/core';
import { SolicitudAnticipoComponent } from './solicitud-anticipo/solicitud-anticipo.component';
import { CommonModule, DatePipe } from '@angular/common';
import { ServicioGeneralService } from '../../servicios/servicio-general.service';
import { PreviewJustificacionModalComponent } from '../preview-justificacion-modal/preview-justificacion-modal.component';
import Swal from 'sweetalert2';

/**
 * Interfaz para las solicitudes de anticipo con tipado completo
 */
interface SolicitudAnticipo {
  id_solicitud: number;
  numero_orden: number;
  tipo: 'efectivo' | 'cheque' | 'transferencia' | 'deposito';
  fecha_creacion: string;
  agencia: number;
  id_estado: number;
  nombre_estado: string;
  nombre_beneficiario: string;
  consignacion: string;
  no_negociable: number;
  monto: string;
  comentario: string;
  comentario_liquidacion: string;
  solicitante: string;
  numero_cuenta: string;
  banco: string;
  tipo_cuenta: string;
  nombre_cuenta: string;
  id_socio: string;
  numero_cuenta_deposito: string;
  id_usuario_recoge: number | null;
  nombre_usuario_recoge: string | null;
  mismo_usuario_recoge: number | null;
  archivo_excepcion_drive_id: string | null;
  archivo_excepcion_nombre: string | null;
  tiene_archivo_excepcion: number;
  comprobante_drive_id: string | null;
  comprobante_nombre: string | null;
  comprobante_tipo: string | null;
  tiene_comprobante_transferencia: number;
}

/**
 * Interfaz para los detalles de una solicitud según su tipo
 */
interface DetalleSolicitud {
  titulo: string;
  icono: string;
  color: string;
  campos: { etiqueta: string; valor: string; tipo?: 'texto' | 'monto' | 'estado' | 'archivo' }[];
}

@Component({
  selector: 'app-solicitudes-anticipo-ordenes',
  standalone: true,
  imports: [
    SolicitudAnticipoComponent,
    CommonModule,
    DatePipe,
    PreviewJustificacionModalComponent
  ],
  templateUrl: './solicitudes-anticipo-ordenes.component.html',
  styleUrl: './solicitudes-anticipo-ordenes.component.css'
})
export class SolicitudesAnticipoOrdenesComponent implements OnInit {
  modalActivo: boolean = false;
  solicitudes: SolicitudAnticipo[] = [];
  cargando: boolean = false;

  @Input() idOrden: number = 0;
  @Input() estadoOrden: number = 0;
  @Output() solicitudCreada = new EventEmitter<any>();
  @Input() origen: number = 1;

  // Variables para eliminar
  mostrarModalEliminar: boolean = false;
  solicitudEliminar: SolicitudAnticipo | null = null;

  // Variables para edición
  modoEdicion: boolean = false;
  solicitudEdicion: any = null;

  // Variables para visualización de archivos
  modalArchivoVisible: boolean = false;
  driveIdSeleccionado: string = '';
  nombreArchivoSeleccionado: string = '';

  // Variables para modal de detalles
  mostrarModalDetalle: boolean = false;
  solicitudDetalle: SolicitudAnticipo | null = null;
  detalleFormateado: DetalleSolicitud | null = null;

  // Catálogos para mostrar nombres legibles
  private catalogoAgencias: { [key: number]: string } = {};
  private catalogoBancos: { [key: number]: string } = {};
  private catalogoTiposCuenta: { [key: number]: string } = {};

  constructor(private servicio: ServicioGeneralService) { }

  ngOnInit() {
    this.cargarCatalogos();
    this.cargarSolicitudes();
  }

  /**
   * Carga los catálogos necesarios para mostrar nombres legibles
   */
  private cargarCatalogos(): void {
    // Cargar agencias
    this.servicio.query({
      ruta: 'facturas/usuarios/listaAgencias',
      tipo: 'get',
      body: {}
    }).subscribe({
      next: res => {
        if (res.respuesta === 'success' && res.datos) {
          res.datos.forEach((agencia: any) => {
            this.catalogoAgencias[agencia.id_agencia] = agencia.nombre;
          });
        }
      },
      error: err => console.warn('Error al cargar agencias:', err)
    });

    // Cargar bancos
    this.servicio.query({
      ruta: 'facturas/bancos/lista',
      tipo: 'get',
      body: {}
    }).subscribe({
      next: res => {
        if (res.respuesta === 'success' && res.datos) {
          res.datos.forEach((banco: any) => {
            this.catalogoBancos[banco.id] = banco.nombre;
          });
        }
      },
      error: err => console.warn('Error al cargar bancos:', err)
    });

    // Cargar tipos de cuenta
    this.servicio.query({
      ruta: 'facturas/tiposCuenta/lista',
      tipo: 'get',
      body: {}
    }).subscribe({
      next: res => {
        if (res.respuesta === 'success' && res.datos) {
          res.datos.forEach((tipo: any) => {
            this.catalogoTiposCuenta[tipo.id] = tipo.nombre;
          });
        }
      },
      error: err => console.warn('Error al cargar tipos de cuenta:', err)
    });
  }

  /**
   * Carga las solicitudes de anticipo de la orden
   */
  cargarSolicitudes() {
    if (!this.idOrden) {
      console.warn('No se ha proporcionado un ID de orden válido');
      return;
    }

    this.cargando = true;

    this.servicio.query({
      ruta: 'contabilidad/obtenerSolicitudesAnticipo',
      tipo: 'post',
      body: {
        id_orden: this.idOrden,
        tipo_solicitud: this.origen
      }
    }).subscribe({
      next: (res: any) => {
        if (res.respuesta === 'success') {
          this.solicitudes = res.datos;
          console.log('Solicitudes cargadas:', this.solicitudes);
        } else {
          this.servicio.mensajeServidor('error', res.mensaje, 'Error');
        }
        this.cargando = false;
      },
      error: (err: any) => {
        console.error('Error al obtener solicitudes:', err);
        this.servicio.mensajeServidor('error', err.mensaje || 'Error al conectar', 'Error');
        this.cargando = false;
      }
    });
  }

  // MÉTODOS PARA MANEJO DE ARCHIVOS

  /**
   * Verifica si una solicitud tiene archivo de excepción
   */
  tieneArchivoExcepcion(solicitud: SolicitudAnticipo): boolean {
    return !!(solicitud.archivo_excepcion_drive_id || solicitud.tiene_archivo_excepcion);
  }

  /**
   * Verifica si una solicitud tiene comprobante de transferencia
   */
  tieneComprobanteTransferencia(solicitud: SolicitudAnticipo): boolean {
    return !!(solicitud.comprobante_drive_id || solicitud.tiene_comprobante_transferencia);
  }

  /**
   * Abre el modal para previsualizar el archivo de excepción
   */
  previsualizarArchivoExcepcion(solicitud: SolicitudAnticipo): void {
    console.log('Previsualizando archivo de excepción para solicitud:', solicitud);

    if (solicitud.archivo_excepcion_drive_id) {
      this.driveIdSeleccionado = solicitud.archivo_excepcion_drive_id;
      this.nombreArchivoSeleccionado = solicitud.archivo_excepcion_nombre || 'archivo_excepcion.pdf';
      this.modalArchivoVisible = true;
    } else {
      this.servicio.mensajeServidor('error', 'No hay archivo de excepción disponible', 'Error');
    }
  }

  /**
   * Abre el modal para previsualizar el comprobante de transferencia
   */
  previsualizarComprobanteTransferencia(solicitud: SolicitudAnticipo): void {
    console.log('Previsualizando comprobante de transferencia para solicitud:', solicitud);

    if (solicitud.comprobante_drive_id) {
      this.driveIdSeleccionado = solicitud.comprobante_drive_id;
      this.nombreArchivoSeleccionado = solicitud.comprobante_nombre || 'comprobante_transferencia.pdf';
      this.modalArchivoVisible = true;
    } else {
      this.servicio.mensajeServidor('error', 'No hay comprobante de transferencia disponible', 'Error');
    }
  }

  /**
   * Cierra el modal de previsualización de archivos
   */
  cerrarModalArchivo(): void {
    this.modalArchivoVisible = false;
    this.driveIdSeleccionado = '';
    this.nombreArchivoSeleccionado = '';
  }

  // MÉTODOS PARA DETALLES DE SOLICITUD

  /**
   * Muestra el modal con los detalles completos de una solicitud
   */
  mostrarDetalleSolicitud(solicitud: SolicitudAnticipo): void {
    this.solicitudDetalle = solicitud;
    this.detalleFormateado = this.formatearDetalleSolicitud(solicitud);
    this.mostrarModalDetalle = true;
  }

  /**
   * Cierra el modal de detalles
   */
  cerrarModalDetalle(): void {
    this.mostrarModalDetalle = false;
    this.solicitudDetalle = null;
    this.detalleFormateado = null;
  }

  /**
   * Formatea los detalles de una solicitud según su tipo
   */
  private formatearDetalleSolicitud(solicitud: SolicitudAnticipo): DetalleSolicitud {
    const base = {
      titulo: '',
      icono: '',
      color: '',
      campos: [
        { etiqueta: 'Número de Solicitud', valor: solicitud.id_solicitud.toString() },
        { etiqueta: 'Fecha de Creación', valor: this.formatearFecha(solicitud.fecha_creacion) },
        { etiqueta: 'Monto', valor: `Q. ${this.formatearMonto(solicitud.monto)}`, tipo: 'monto' as const },
        { etiqueta: 'Estado', valor: solicitud.nombre_estado || 'Pendiente', tipo: 'estado' as const },
        { etiqueta: 'Solicitante', valor: solicitud.solicitante },
        { etiqueta: 'Motivo', valor: solicitud.comentario || 'Sin especificar' }
      ]
    };

    switch (solicitud.tipo) {
      case 'efectivo':
        return {
          ...base,
          titulo: 'Solicitud de Efectivo',
          color: 'blue',
          campos: [
            ...base.campos,
            { etiqueta: 'Agencia', valor: this.obtenerNombreAgencia(solicitud.agencia) },
            {
              etiqueta: 'Quien Recoge',
              valor: solicitud.mismo_usuario_recoge === 1
                ? 'El mismo solicitante'
                : (solicitud.nombre_usuario_recoge || 'No especificado')
            }
          ]
        };

      case 'cheque':
        return {
          ...base,
          titulo: 'Solicitud de Cheque',
          color: 'green',
          campos: [
            ...base.campos,
            { etiqueta: 'Beneficiario', valor: solicitud.nombre_beneficiario || 'No especificado' },
            { etiqueta: 'Tipo de Consignación', valor: solicitud.consignacion || 'No especificado' },
            { etiqueta: 'Negociable', valor: solicitud.no_negociable === 1 ? 'No' : 'Sí' }
          ]
        };

      case 'transferencia':
        return {
          ...base,
          titulo: 'Solicitud de Transferencia',
          color: 'purple',
          campos: [
            ...base.campos,
            { etiqueta: 'Titular de la Cuenta', valor: solicitud.nombre_cuenta || 'No especificado' },
            { etiqueta: 'Número de Cuenta', valor: solicitud.numero_cuenta || 'No especificado' },
            { etiqueta: 'Banco', valor: this.obtenerNombreBanco(parseInt(solicitud.banco)) },
            { etiqueta: 'Tipo de Cuenta', valor: this.obtenerTipoCuenta(parseInt(solicitud.tipo_cuenta)) },
            ...(this.tieneComprobanteTransferencia(solicitud) ?
              [{ etiqueta: 'Comprobante', valor: 'Disponible', tipo: 'archivo' as const }] : [])
          ]
        };

      case 'deposito':
        return {
          ...base,
          titulo: 'Solicitud de Depósito',
          color: 'sky',
          campos: [
            ...base.campos,
            { etiqueta: 'ID del Socio', valor: solicitud.id_socio || 'No especificado' },
            { etiqueta: 'Número de Cuenta', valor: solicitud.numero_cuenta_deposito || 'No especificado' },
            { etiqueta: 'Nombre del Socio', valor: this.obtenerNombreSocio(solicitud) },
            ...(this.tieneArchivoExcepcion(solicitud) ?
              [{ etiqueta: 'Archivo de Excepción', valor: 'Disponible', tipo: 'archivo' as const }] : [])
          ]
        };

      default:
        return {
          ...base,
          titulo: 'Solicitud de Anticipo',
          color: 'gray'
        };
    }
  }

  // MÉTODOS AUXILIARES PARA FORMATEO

  /**
   * Obtiene el nombre de la agencia
   */
  private obtenerNombreAgencia(idAgencia: number): string {
    return this.catalogoAgencias[idAgencia] || `Agencia ${idAgencia}`;
  }

  /**
   * Obtiene el nombre del banco
   */
  private obtenerNombreBanco(idBanco: number): string {
    return this.catalogoBancos[idBanco] || `Banco ${idBanco}`;
  }

  /**
   * Obtiene el tipo de cuenta
   */
  private obtenerTipoCuenta(idTipo: number): string {
    return this.catalogoTiposCuenta[idTipo] || `Tipo ${idTipo}`;
  }

  /**
   * Obtiene el nombre del socio para depósitos
   */
  private obtenerNombreSocio(solicitud: SolicitudAnticipo): string {
    // En depósitos, el nombre podría estar en nombre_cuenta o necesitar ser consultado
    return solicitud.nombre_cuenta || 'Consultar en sistema';
  }

  /**
   * Formatea una fecha para mostrar
   */
  private formatearFecha(fecha: string): string {
    try {
      return new Date(fecha).toLocaleDateString('es-GT', {
        year: 'numeric',
        month: 'long',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
      });
    } catch {
      return fecha;
    }
  }

  /**
   * Formatea un monto para mostrar
   */
  private formatearMonto(monto: string): string {
    try {
      return parseFloat(monto).toLocaleString('es-GT', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
      });
    } catch {
      return monto;
    }
  }

  /**
   * Obtiene la configuración de estilo para cada tipo de solicitud
   */
  obtenerConfiguracionTipo(tipo: string): { color: string; icono: string; nombre: string } {
    const configuraciones = {
      efectivo: { color: 'blue', icono: '', nombre: 'Efectivo' },
      cheque: { color: 'green', icono: '', nombre: 'Cheque' },
      transferencia: { color: 'purple', icono: '', nombre: 'Transferencia' },
      deposito: { color: 'sky', icono: '', nombre: 'Depósito' }
    };
    return configuraciones[tipo as keyof typeof configuraciones] ||
      { color: 'gray', icono: '📄', nombre: tipo };
  }

  /**
   * Obtiene el resumen del beneficiario/recoge según el tipo
   */
  obtenerResumenBeneficiario(solicitud: SolicitudAnticipo): string {
    switch (solicitud.tipo) {
      case 'efectivo':
        return solicitud.mismo_usuario_recoge === 1
          ? 'El mismo solicitante'
          : (solicitud.nombre_usuario_recoge || 'No especificado');

      case 'cheque':
        return solicitud.nombre_beneficiario || 'Sin beneficiario';

      case 'transferencia':
        return solicitud.nombre_cuenta || 'Sin titular';

      case 'deposito':
        return `Socio ID: ${solicitud.id_socio || 'N/A'}`;

      default:
        return 'No especificado';
    }
  }

  // MÉTODOS EXISTENTES (sin cambios)

  abrirModal() {
    this.modoEdicion = false;
    this.solicitudEdicion = null;
    this.modalActivo = true;
  }

  cerrarModal() {
    this.modalActivo = false;
    if (!this.modoEdicion) {
      this.solicitudEdicion = null;
    }
  }

  editarSolicitud(solicitud: SolicitudAnticipo) {
    this.modoEdicion = true;
    this.solicitudEdicion = { ...solicitud };

    if (solicitud.tipo === 'efectivo') {
      this.solicitudEdicion.mismo_usuario_recoge = solicitud.mismo_usuario_recoge === 1;
      if (solicitud.mismo_usuario_recoge === 0) {
        this.solicitudEdicion.usuario_recoge = solicitud.id_usuario_recoge;
      } else {
        this.solicitudEdicion.usuario_recoge = '';
      }
    }

    // Para depósitos, adaptar los datos
    if (solicitud.tipo === 'deposito') {
      this.solicitudEdicion.id_socio = parseFloat(solicitud.id_socio);
      this.solicitudEdicion.id_cuenta = solicitud.numero_cuenta_deposito;
    }

    this.modalActivo = true;
  }

  guardarSolicitud(data: any) {
    console.log('Solicitud recibida del modal:', data);
    this.cargarSolicitudes();
    this.modalActivo = false;
    this.solicitudCreada.emit(data);
  }

  confirmarEliminar(solicitud: SolicitudAnticipo) {
    this.solicitudEliminar = solicitud;
    this.mostrarModalEliminar = true;
  }

  cancelarEliminar() {
    this.solicitudEliminar = null;
    this.mostrarModalEliminar = false;
  }

  eliminarSolicitud() {
    if (!this.solicitudEliminar || !this.solicitudEliminar.id_solicitud) {
      this.cancelarEliminar();
      return;
    }

    this.cargando = true;

    this.servicio.query({
      ruta: 'facturas/anularSolicitudAnticipo',
      body: { id_solicitud: this.solicitudEliminar.id_solicitud }
    }).subscribe({
      next: (res: any) => {
        if (res.respuesta === 'success') {
          this.servicio.mensajeServidor('success', res.mensaje, 'Éxito');
          this.solicitudes = this.solicitudes.filter(
            s => s.id_solicitud !== this.solicitudEliminar!.id_solicitud
          );
          this.solicitudCreada.emit(null);
        } else {
          this.servicio.mensajeServidor('error', res.mensaje, 'Error');
        }

        this.cargando = false;
        this.cancelarEliminar();
      },
      error: (err: any) => {
        this.servicio.mensajeServidor('error', err?.mensaje || 'Error al eliminar', 'Error');
        this.cargando = false;
        this.cancelarEliminar();
      }
    });
  }
}