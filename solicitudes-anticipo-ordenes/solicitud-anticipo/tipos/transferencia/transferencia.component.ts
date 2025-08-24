// tipos/transferencia/transferencia.component.ts

import { Component } from '@angular/core';
import { CommonModule } from '@angular/common';
import { ReactiveFormsModule, Validators } from '@angular/forms';
import { BaseFormComponent } from '../../shared/base.component';
import { DatosAnticipo } from '../../models/anticipo.model';

@Component({
  selector: 'app-transferencia',
  standalone: true,
  imports: [CommonModule, ReactiveFormsModule],
  templateUrl: './transferencia.component.html',
  styleUrl: './transferencia.component.css'
})
export class TransferenciaFormComponent extends BaseFormComponent {

  inicializarFormulario(): void {
    this.formulario = this.fb.group({
      monto: [null, [Validators.required, Validators.min(0.01)]],
      nombre_cuenta: ['', [Validators.required, Validators.minLength(3)]],
      numero_cuenta: ['', [Validators.required, Validators.minLength(8)]],
      banco: ['', Validators.required],
      tipo_cuenta: ['', Validators.required],
      comentario: ['', Validators.required],
      id_orden: [this.idOrden],
      tipo_solicitud: [this.tipoSolicitud]
    });
  }

  cargarDatosExternos(): void {
    this.obtenerBancos();
    this.obtenerTiposCuenta();
  }

  override cargarDatos(datos: DatosAnticipo): void {
    super.cargarDatos(datos);

    this.formulario.patchValue({
      nombre_cuenta: datos.nombre_cuenta || '',
      numero_cuenta: datos.numero_cuenta || '',
      banco: datos.banco || '',
      tipo_cuenta: datos.tipo_cuenta || ''
    });
  }

  obtenerDatos(): DatosAnticipo {
    const formData = this.formulario.value;

    return {
      tipo: 'transferencia',
      monto: formData.monto,
      comentario: formData.comentario,
      nombre_cuenta: formData.nombre_cuenta,
      numero_cuenta: formData.numero_cuenta,
      banco: formData.banco,
      tipo_cuenta: formData.tipo_cuenta,
      id_orden: formData.id_orden || this.idOrden,
      tipo_solicitud: formData.tipo_solicitud || this.tipoSolicitud,
      concepto: formData.comentario,
      solicitante: 'Usuario actual',
      detalle: formData.comentario
    };
  }

  // Métodos para manejo de archivos específicos de transferencia
  abrirSelectorArchivo(): void {
    const inputElement = document.getElementById('archivo-input') as HTMLInputElement;
    if (inputElement) {
      inputElement.click();
    }
  }
}