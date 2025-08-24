// shared/base-form.component.ts

import { Component, Input, Output, EventEmitter, OnInit, OnChanges, SimpleChanges } from '@angular/core';
import { FormBuilder, FormGroup, Validators } from '@angular/forms';
import { ServicioGeneralService } from '../../../../servicios/servicio-general.service';
import { DatosAnticipo, Agencia, Banco, TipoCuenta, Usuario } from '../models/anticipo.model';
import Swal from 'sweetalert2';

@Component({
    template: ''
})
export abstract class BaseFormComponent implements OnInit, OnChanges {

    @Input() modoEdicion: boolean = false;
    @Input() datosEdicion: DatosAnticipo | null = null;
    @Input() idOrden: number = 0;
    @Input() tipoSolicitud: number = 1;

    @Output() guardar = new EventEmitter<DatosAnticipo>();
    @Output() cancelar = new EventEmitter<void>();

    formulario!: FormGroup;
    mensajeError: string = '';
    guardando: boolean = false;

    // Datos para selectores
    agencias: Agencia[] = [];
    bancos: Banco[] = [];
    tiposCuenta: TipoCuenta[] = [];
    usuarios: Usuario[] = [];

    // Estados de carga
    cargandoAgencias: boolean = false;
    cargandoBancos: boolean = false;
    cargandoTiposCuenta: boolean = false;
    cargandoUsuarios: boolean = false;

    // Archivos
    archivoSeleccionado: File | null = null;
    nombreArchivo: string = '';

    constructor(
        protected fb: FormBuilder,
        protected servicio: ServicioGeneralService
    ) { }

    ngOnInit(): void {
        this.inicializarFormulario();
        this.cargarDatosExternos();
    }

    ngOnChanges(changes: SimpleChanges): void {
        if (!this.formulario) return;

        if (this.modoEdicion && this.datosEdicion) {
            this.cargarDatos(this.datosEdicion);
        } else {
            this.resetearFormulario();
        }
    }

    // Métodos abstractos
    abstract inicializarFormulario(): void;
    abstract obtenerDatos(): DatosAnticipo;
    abstract cargarDatosExternos(): void;

    // Métodos comunes
    resetearFormulario(): void {
        this.mensajeError = '';
        this.archivoSeleccionado = null;
        this.nombreArchivo = '';
        this.formulario.reset({
            monto: null,
            comentario: '',
            id_orden: this.idOrden,
            tipo_solicitud: this.tipoSolicitud
        });
    }

    cargarDatos(datos: DatosAnticipo): void {
        this.formulario.patchValue({
            monto: datos.monto,
            comentario: datos.comentario || datos.concepto || '',
            id_orden: datos.id_orden || this.idOrden,
            tipo_solicitud: datos.tipo_solicitud || this.tipoSolicitud
        });
    }

    submit(): void {
        if (this.formulario.invalid) {
            this.formulario.markAllAsTouched();
            return;
        }

        this.guardando = true;
        this.mensajeError = '';

        const datos = this.obtenerDatos();
        this.enviarSolicitud(datos);
    }

    private enviarSolicitud(datos: DatosAnticipo): void {
        const esActualizacion = this.modoEdicion && this.datosEdicion?.id_solicitud;
        const ruta = esActualizacion
            ? 'contabilidad/actualizarSolicitudAnticipoConArchivo'
            : 'contabilidad/crearSolicitudAnticipoConArchivo';

        if (esActualizacion) {
            datos.id_solicitud = this.datosEdicion?.id_solicitud;
        }

        const archivos: File[] = this.archivoSeleccionado ? [this.archivoSeleccionado] : [];

        this.servicio.queryFormData(ruta, datos, archivos).subscribe({
            next: (res: any) => {
                if (res.respuesta === 'success') {
                    this.servicio.mensajeServidor('success', res.mensaje, 'Éxito');
                    this.guardar.emit(datos);
                    this.cancelar.emit();
                } else if (res.respuesta === 'info') {
                    this.mostrarModalInfo(res);
                } else {
                    Swal.fire('Advertencia', res.mensaje, 'warning');
                }
                this.guardando = false;
            },
            error: (err: any) => {
                this.servicio.mensajeServidor('error', err?.mensaje || 'Error inesperado', 'Error');
                this.guardando = false;
            }
        });
    }

    private mostrarModalInfo(res: any): void {
        let htmlContent = `<div style="font-weight: bold; margin-bottom: 15px;">${res.mensaje}</div>`;

        if (res.total_solicitado !== undefined && res.monto_disponible !== undefined) {
            htmlContent += `
        <div style="text-align: left;">
          <div><strong>Total Solicitado:</strong> Q${this.formatearNumero(res.total_solicitado)}</div>
          <div><strong>Monto Disponible:</strong> Q${this.formatearNumero(res.monto_disponible)}</div>
        </div>`;
        }

        Swal.fire({
            title: 'Información de Límites',
            html: htmlContent,
            icon: 'info',
            confirmButtonText: 'Entendido'
        });
    }

    private formatearNumero(numero: number): string {
        return new Intl.NumberFormat('es-GT', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        }).format(numero);
    }

    // Métodos para cargar datos externos
    protected obtenerAgencias(): void {
        if (this.agencias.length > 0) return;

        this.cargandoAgencias = true;
        this.servicio.query({
            ruta: 'facturas/usuarios/listaAgencias',
            tipo: 'get',
            body: {}
        }).subscribe({
            next: res => {
                if (res.respuesta === 'success') {
                    this.agencias = res.datos || [];
                }
                this.cargandoAgencias = false;
            },
            error: () => {
                this.cargandoAgencias = false;
                this.servicio.mensajeServidor('error', 'Error al cargar agencias');
            }
        });
    }

    protected obtenerBancos(): void {
        if (this.bancos.length > 0) return;

        this.cargandoBancos = true;
        this.servicio.query({
            ruta: 'facturas/bancos/lista',
            tipo: 'get',
            body: {}
        }).subscribe({
            next: res => {
                if (res.respuesta === 'success') {
                    this.bancos = res.datos || [];
                }
                this.cargandoBancos = false;
            },
            error: () => {
                this.cargandoBancos = false;
                this.servicio.mensajeServidor('error', 'Error al cargar bancos');
            }
        });
    }

    protected obtenerTiposCuenta(): void {
        if (this.tiposCuenta.length > 0) return;

        this.cargandoTiposCuenta = true;
        this.servicio.query({
            ruta: 'facturas/tiposCuenta/lista',
            tipo: 'get',
            body: {}
        }).subscribe({
            next: res => {
                if (res.respuesta === 'success') {
                    this.tiposCuenta = res.datos || [];
                }
                this.cargandoTiposCuenta = false;
            },
            error: () => {
                this.cargandoTiposCuenta = false;
                this.servicio.mensajeServidor('error', 'Error al cargar tipos de cuenta');
            }
        });
    }

    protected obtenerUsuarios(): void {
        if (this.usuarios.length > 0) return;

        this.cargandoUsuarios = true;
        this.servicio.query({
            ruta: 'facturas/usuarios/listaUsuarios',
            tipo: 'get',
            body: {}
        }).subscribe({
            next: res => {
                if (res.respuesta === 'success') {
                    this.usuarios = res.datos || [];
                }
                this.cargandoUsuarios = false;
            },
            error: () => {
                this.cargandoUsuarios = false;
                this.servicio.mensajeServidor('error', 'Error al cargar usuarios');
            }
        });
    }

    // Métodos para archivos
    seleccionarArchivo(event: Event): void {
        const element = event.target as HTMLInputElement;
        if (element.files && element.files.length > 0) {
            const file = element.files[0];

            const tiposPermitidos = ['application/pdf', 'image/jpeg', 'image/png', 'image/jpg'];
            if (!tiposPermitidos.includes(file.type)) {
                this.servicio.mensajeServidor('error', 'Solo se permiten archivos PDF, JPG, JPEG o PNG', 'Error');
                element.value = '';
                return;
            }

            if (file.size > 5 * 1024 * 1024) {
                this.servicio.mensajeServidor('error', 'El tamaño máximo permitido es 5MB', 'Error');
                element.value = '';
                return;
            }

            this.archivoSeleccionado = file;
            this.nombreArchivo = file.name;
        }
    }

    eliminarArchivo(): void {
        this.archivoSeleccionado = null;
        this.nombreArchivo = '';
        const inputElement = document.getElementById('archivo-input') as HTMLInputElement;
        if (inputElement) {
            inputElement.value = '';
        }
    }
}