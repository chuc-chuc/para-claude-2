// tipos/deposito/deposito.component.ts

import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { ReactiveFormsModule, FormControl, Validators } from '@angular/forms';
import { BaseFormComponent } from '../../shared/base.component';
import { DatosAnticipo, Socio, CuentaSocio } from '../../models/anticipo.model';
import { distinctUntilChanged, catchError, of, switchMap } from 'rxjs';

/**
 * Componente para formulario de depósito a cuenta de socio
 * Permite buscar socios por ID o DPI y seleccionar sus cuentas
 */
@Component({
  selector: 'app-deposito',
  standalone: true,
  imports: [CommonModule, ReactiveFormsModule],
  templateUrl: './deposito.component.html',
  styleUrl: './deposito.component.css'
})
export class DepositoFormComponent extends BaseFormComponent implements OnInit {

  /** Control para la búsqueda de socios */
  buscadorSocio = new FormControl('');

  /** Lista de socios encontrados en la búsqueda */
  sociosEncontrados: Socio[] = [];

  /** Socio actualmente seleccionado */
  socioSeleccionado: Socio | null = null;

  /** Cuentas del socio seleccionado */
  cuentasSocio: CuentaSocio[] = [];

  /** Estados de carga */
  buscandoSocios = false;
  cargandoCuentas = false;

  /** Flags para mostrar/ocultar elementos */
  mostrarResultadosBusqueda = false;
  mostrarDetallesSocio = false;

  override ngOnInit(): void {
    super.ngOnInit();
    this.configurarBuscadorSocios();
  }

  /**
   * Inicializa el formulario específico para depósitos
   */
  inicializarFormulario(): void {
    this.formulario = this.fb.group({
      monto: [null, [Validators.required, Validators.min(0.01)]],
      id_socio: ['', Validators.required],
      id_cuenta: ['', Validators.required],
      comentario: ['', Validators.required],
      id_orden: [this.idOrden],
      tipo_solicitud: [this.tipoSolicitud]
    });
  }

  /**
   * Carga datos externos necesarios para el formulario
   */
  cargarDatosExternos(): void {
    // No necesita cargar datos externos al inicio
    // Los socios se buscan dinámicamente
  }

  /**
   * Carga datos existentes en modo edición
   */
  override cargarDatos(datos: DatosAnticipo): void {
    super.cargarDatos(datos);

    if (datos.id_socio) {
      // Cargar datos del socio seleccionado
      this.cargarSocioPorId(datos.id_socio);

      this.formulario.patchValue({
        id_socio: datos.id_socio,
        id_cuenta: datos.id_cuenta || ''
      });
    }
  }

  /**
   * Configura el buscador de socios - Solo búsqueda manual
   */
  private configurarBuscadorSocios(): void {
    // Solo limpiamos resultados cuando se borra el campo
    this.buscadorSocio.valueChanges.pipe(
      distinctUntilChanged()
    ).subscribe(termino => {
      // Si el campo se vacía, limpiar resultados
      if (!termino || termino.length === 0) {
        this.sociosEncontrados = [];
        this.mostrarResultadosBusqueda = false;
        this.limpiarMensajeError();
      }
    });
  }

  /**
   * Realiza búsqueda manual de socios
   */
  buscarManualmente(): void {
    const termino = this.buscadorSocio.value?.trim();

    if (!termino || termino.length < 2) {
      this.mensajeError = 'Debe escribir al menos 2 caracteres para buscar';
      return;
    }

    this.buscandoSocios = true;
    this.limpiarMensajeError();
    this.sociosEncontrados = [];
    this.mostrarResultadosBusqueda = false;

    console.log('Buscando término:', termino); // Para debug

    this.buscarSocios(termino).subscribe({
      next: (socios) => {
        console.log('Socios recibidos:', socios); // Para debug
        this.buscandoSocios = false;
        this.sociosEncontrados = socios || [];
        this.mostrarResultadosBusqueda = this.sociosEncontrados.length > 0;

        if (this.sociosEncontrados.length === 0) {
          this.mensajeError = 'No se encontraron socios con ese ID o DPI';
        }
      },
      error: (error) => {
        console.error('Error al buscar socios:', error);
        this.buscandoSocios = false;
        this.mensajeError = 'Error al buscar socios. Inténtelo nuevamente.';
        this.sociosEncontrados = [];
        this.mostrarResultadosBusqueda = false;
      }
    });
  }

  /**
   * Busca socios por término (ID o DPI) usando tu backend
   * @param termino - Término de búsqueda
   * @returns Observable con los socios encontrados
   */
  private buscarSocios(termino: string) {
    return this.servicio.query({
      ruta: 'contabilidad/buscar_socios',
      tipo: 'post',
      body: { termino }
    }).pipe(
      switchMap(res => {
        console.log('Respuesta del backend buscar_socios:', res); // Para debug
        if (res.respuesta === 'success') {
          return of(res.datos || []);
        } else {
          throw new Error(res.mensaje || 'Error al buscar socios');
        }
      }),
      catchError(error => {
        console.error('Error en buscarSocios:', error);
        throw error;
      })
    );
  }

  /**
   * Selecciona un socio de los resultados de búsqueda
   * @param socio - Socio seleccionado
   */
  seleccionarSocio(socio: Socio): void {
    this.socioSeleccionado = socio;
    this.mostrarResultadosBusqueda = false;
    this.mostrarDetallesSocio = true;
    this.limpiarMensajeError();

    // Actualizar el campo de búsqueda con el socio seleccionado
    this.buscadorSocio.setValue(`${socio.id_socio} - ${socio.nombre}`, { emitEvent: false });

    // Actualizar el formulario
    this.formulario.patchValue({
      id_socio: socio.id_socio,
      id_cuenta: '' // Limpiar cuenta seleccionada al cambiar socio
    });

    // Cargar las cuentas del socio
    this.cargarCuentasSocio(socio.id_socio);
  }

  /**
   * Carga las cuentas de un socio específico usando tu backend
   * @param idSocio - ID del socio
   */
  private cargarCuentasSocio(idSocio: number): void {
    this.cargandoCuentas = true;
    this.cuentasSocio = [];
    this.limpiarMensajeError();

    this.servicio.query({
      ruta: 'contabilidad/buscar_cuentas',
      tipo: 'post',
      body: { id_socio: idSocio }
    }).subscribe({
      next: res => {
        this.cargandoCuentas = false;
        if (res.respuesta === 'success') {
          this.cuentasSocio = res.datos || [];
          if (this.cuentasSocio.length === 0) {
            this.mensajeError = 'Este socio no tiene cuentas disponibles';
          }
        } else {
          this.mensajeError = res.mensaje || 'Error al cargar las cuentas del socio';
        }
      },
      error: error => {
        this.cargandoCuentas = false;
        console.error('Error al cargar cuentas:', error);
        this.mensajeError = 'Error al cargar las cuentas del socio. Inténtelo nuevamente.';
      }
    });
  }

  /**
   * Carga un socio por su ID (usado en modo edición)
   * @param idSocio - ID del socio
   */
  private cargarSocioPorId(idSocio: number): void {
    this.servicio.query({
      ruta: 'contabilidad/obtener_socio',
      tipo: 'post',
      body: { id_socio: idSocio }
    }).subscribe({
      next: res => {
        if (res.respuesta === 'success' && res.datos) {
          this.seleccionarSocio(res.datos);
        } else {
          this.mensajeError = 'Error al cargar los datos del socio';
        }
      },
      error: error => {
        console.error('Error al cargar socio:', error);
        this.mensajeError = 'Error al cargar los datos del socio';
      }
    });
  }

  /**
   * Limpia la selección de socio y resetea el formulario
   */
  limpiarSeleccionSocio(): void {
    this.socioSeleccionado = null;
    this.cuentasSocio = [];
    this.mostrarDetallesSocio = false;
    this.mostrarResultadosBusqueda = false;
    this.sociosEncontrados = [];
    this.limpiarMensajeError();
    this.buscadorSocio.setValue('');

    this.formulario.patchValue({
      id_socio: '',
      id_cuenta: ''
    });
  }

  /**
   * Limpia el mensaje de error
   */
  private limpiarMensajeError(): void {
    this.mensajeError = '';
  }

  /**
   * Obtiene los datos del formulario en formato DatosAnticipo
   * Adaptado a tu estructura de CuentaSocio con NumeroCuenta
   */
  obtenerDatos(): DatosAnticipo {
    const formData = this.formulario.value;

    // Buscar la cuenta seleccionada por NumeroCuenta
    const cuentaSeleccionada = this.cuentasSocio.find(c =>
      c.NumeroCuenta === Number(formData.id_cuenta)
    );

    return {
      tipo: 'deposito',
      monto: formData.monto,
      comentario: formData.comentario,
      id_socio: formData.id_socio,
      id_cuenta: formData.id_cuenta, // Este será el NumeroCuenta
      numero_cuenta: cuentaSeleccionada?.NumeroCuenta?.toString() || '',
      nombre_cuenta: this.socioSeleccionado?.nombre || '',
      nombre_socio: this.socioSeleccionado?.nombre || '',
      id_orden: formData.id_orden || this.idOrden,
      tipo_solicitud: formData.tipo_solicitud || this.tipoSolicitud,
      concepto: formData.comentario,
      solicitante: 'Usuario actual',
      detalle: `Depósito a cuenta ${cuentaSeleccionada?.NumeroCuenta} (${cuentaSeleccionada?.Producto}) de ${this.socioSeleccionado?.nombre}`
    };
  }

  /**
   * Obtiene el nombre del producto de manera más legible
   * @param producto - Nombre del producto desde el backend
   * @returns Nombre formateado
   */
  obtenerNombreProducto(producto: string): string {
    if (!producto) return 'Cuenta';

    // Convertir "114A.AHORRO.DISPONIBLE" a "Ahorro Disponible"
    const partes = producto.split('.');
    if (partes.length >= 2) {
      return partes.slice(1).join(' ').toLowerCase()
        .replace(/\b\w/g, l => l.toUpperCase());
    }

    return producto;
  }

  /**
   * Métodos para manejo de archivos específicos de depósito
   */
  abrirSelectorArchivo(): void {
    const inputElement = document.getElementById('archivo-input') as HTMLInputElement;
    if (inputElement) {
      inputElement.click();
    }
  }
}